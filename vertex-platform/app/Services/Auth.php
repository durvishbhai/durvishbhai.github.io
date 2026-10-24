<?php

declare(strict_types=1);

namespace Vertex\Services;

use Vertex\Core\Config;
use Vertex\Core\Csrf;
use Vertex\Core\Logger;
use Vertex\Core\Request;
use Vertex\Core\Session;
use Vertex\Repositories\UserRepository;

/**
 * Session authentication.
 *  - Passwords: password_hash(PASSWORD_DEFAULT) (bcrypt/argon2 as supported), transparently rehashed on login.
 *  - Throttling per email+IP and per IP; generic failure message (no account enumeration; dummy hash on unknown email).
 *  - The session is bound to HMAC(password_hash | session_version). A password change, deactivation, archive or any
 *    session_version bump invalidates every existing session on the very next request.
 *  - Account status is re-read from the database on every request, so deactivation is immediate.
 *  - Session ID and CSRF token are regenerated on login and logout.
 */
final class Auth
{
    public const OK = 'ok';
    public const INVALID = 'invalid';
    public const LOCKED = 'locked';
    public const INACTIVE = 'inactive';
    public const PENDING = 'pending';
    public const TWO_FACTOR = 'two_factor';
    public const EXPIRED = 'expired';

    /** @var array<string,mixed>|null|false false = not resolved yet */
    private static array|null|false $user = false;

    private static ?string $dummyHash = null;

    public static function id(): ?int
    {
        $u = self::user();

        return $u === null ? null : (int) $u['id'];
    }

    /** @return array<string,mixed>|null */
    public static function user(): ?array
    {
        if (self::$user !== false) {
            return self::$user;
        }
        $id = Session::get('auth_user_id');
        if (!is_int($id)) {
            return self::$user = null;
        }
        $user = (new UserRepository())->findById($id);
        if ($user === null || $user['status'] !== 'active' || !hash_equals(self::fingerprint($user), (string) Session::get('auth_fp', ''))) {
            self::forget();

            return self::$user = null;
        }

        return self::$user = $user;
    }

    /** @return self::OK|self::INVALID|self::LOCKED|self::INACTIVE|self::PENDING */
    public static function attempt(string $email, string $password, Request $request): string
    {
        $email = mb_strtolower(trim($email));
        $ip = $request->ip();
        $throttle = new LoginThrottle();

        if ($throttle->isLocked($email, $ip)) {
            Audit::record('auth.login.locked', 'user', null, [], [], ['email' => $email], null, $ip, $request->userAgent());

            return self::LOCKED;
        }

        $repo = new UserRepository();
        $user = $repo->findByEmail($email);
        // Always run a hash verification so response time doesn't reveal whether the email exists.
        $hash = $user['password_hash'] ?? (self::$dummyHash ??= password_hash(bin2hex(random_bytes(12)), PASSWORD_DEFAULT));
        $valid = password_verify($password, (string) $hash) && $user !== null;

        if (!$valid) {
            $throttle->record($email, $ip, false);
            Audit::record('auth.login.failed', 'user', $user !== null ? (int) $user['id'] : null, [], [], ['email' => $email], null, $ip, $request->userAgent());

            return self::INVALID;
        }
        if ($user['status'] !== 'active') {
            $throttle->record($email, $ip, false);
            Audit::record('auth.login.blocked', 'user', (int) $user['id'], [], [], ['status' => $user['status']], null, $ip, $request->userAgent());

            return $user['status'] === 'pending' ? self::PENDING : self::INACTIVE;
        }

        if (password_needs_rehash((string) $user['password_hash'], PASSWORD_DEFAULT)) {
            $user['password_hash'] = password_hash($password, PASSWORD_DEFAULT);
            $repo->updatePasswordHash((int) $user['id'], $user['password_hash']);
        }

        $throttle->record($email, $ip, true);
        if (TwoFactorService::isEnabled($user)) {
            // Password is right, but the person is NOT signed in until the second factor is checked.
            Session::regenerate();
            Session::put('twofa_pending', ['uid' => (int) $user['id'], 'exp' => time() + 300]);
            Audit::record('auth.login.password_ok_2fa_pending', 'user', (int) $user['id'], [], [], [], (int) $user['id'], $ip, $request->userAgent());

            return self::TWO_FACTOR;
        }
        self::login($user);
        $repo->touchLogin((int) $user['id'], $ip);
        Audit::record('auth.login.success', 'user', (int) $user['id'], [], [], [], (int) $user['id'], $ip, $request->userAgent());
        self::signInAlert($user, $request, 'Email and password');

        return self::OK;
    }

    /** The user whose password was accepted and who still owes the second factor, or null (none, or the 5 minutes ran out). */
    public static function pendingTwoFactorUser(): ?array
    {
        $p = Session::get('twofa_pending');
        if (!is_array($p) || (int) ($p['exp'] ?? 0) < time()) {
            Session::forget('twofa_pending');

            return null;
        }
        $user = (new UserRepository())->findById((int) $p['uid']);

        return $user !== null && $user['status'] === 'active' && TwoFactorService::isEnabled($user) ? $user : null;
    }

    /** @return self::OK|self::INVALID|self::LOCKED|self::EXPIRED */
    public static function completeTwoFactor(string $code, Request $request): string
    {
        $user = self::pendingTwoFactorUser();
        if ($user === null) {
            return self::EXPIRED;
        }
        $result = TwoFactorService::verifyLogin((int) $user['id'], $code);
        if ($result === 'locked') {
            return self::LOCKED;
        }
        if ($result !== 'ok') {
            return self::INVALID;
        }
        Session::forget('twofa_pending');
        self::login($user);
        (new UserRepository())->touchLogin((int) $user['id'], $request->ip());
        Audit::record('auth.login.success', 'user', (int) $user['id'], [], [], ['two_factor' => true], (int) $user['id'], $request->ip(), $request->userAgent());
        self::signInAlert($user, $request, 'Email, password and a verification code');

        return self::OK;
    }

    /**
     * Emails the person "you signed in" so they can spot a sign-in they did not make. Not sent twice in a row from the same address within ten minutes, and
     * never allowed to delay or break the sign-in. $user is the record as it was BEFORE this sign-in was stamped.
     *
     * @param array<string,mixed> $user
     */
    public static function signInAlert(array $user, Request $request, string $method): void
    {
        try {
            if (!(bool) Config::get('notifications.login_alert', true) || !Mailer::enabled()) {
                return;
            }
            $ip = $request->ip();
            if (($user['last_login_ip'] ?? null) === $ip && isset($user['last_login_at']) && strtotime((string) $user['last_login_at'] . ' UTC') > time() - 600) {
                return;
            }
            $to = (string) $user['email'];
            $name = (string) $user['name'];
            $ua = $request->userAgent();
            $when = gmdate('d M Y, H:i') . ' UTC';
            EmailChannel::afterResponse(static function () use ($to, $name, $when, $ip, $ua, $method): void {
                AccountMail::signedIn($to, $name, $when, $ip, EmailTemplate::browser($ua), $method);
            });
        } catch (\Throwable $e) {
            Logger::error('Sign-in alert failed', ['error' => $e->getMessage()]);
        }
    }

    /** @param array<string,mixed> $user */
    public static function login(array $user): void
    {
        Session::regenerate();
        Csrf::rotate();
        Session::put('auth_user_id', (int) $user['id']);
        Session::put('auth_fp', self::fingerprint($user));
        self::$user = $user;
        Gate::flush();
    }

    /** Re-binds the current session after the user's own password change (their other sessions stay invalid). */
    public static function refresh(): void
    {
        $id = Session::get('auth_user_id');
        if (is_int($id)) {
            $user = (new UserRepository())->findById($id);
            if ($user !== null) {
                Session::regenerate();
                Session::put('auth_fp', self::fingerprint($user));
                self::$user = $user;
            }
        }
    }

    /**
     * Step-up check for sensitive actions: is $password the current password of $userId?
     * Rate limited (5 / 10 min per user); failures are audited. The password is never logged.
     */
    public static function verifyPassword(int $userId, string $password): bool
    {
        if (RateLimiter::tooMany('stepup_user', (string) $userId)) {
            Audit::record('auth.stepup.locked', 'user', $userId, [], [], [], $userId);

            return false;
        }
        $user = (new UserRepository())->findById($userId);
        $ok = $user !== null && password_verify($password, (string) $user['password_hash']);
        if (!$ok) {
            RateLimiter::hit('stepup_user', (string) $userId);
            Audit::record('auth.stepup.failed', 'user', $userId, [], [], [], $userId);
        } else {
            RateLimiter::clear('stepup_user', (string) $userId);
        }

        return $ok;
    }

    public static function logout(): void
    {
        $id = self::id();
        if ($id !== null) {
            Audit::record('auth.logout', 'user', $id, [], [], [], $id);
        }
        self::forget();
        Session::destroy();
    }

    public static function reset(): void
    {
        self::$user = false;
    }

    private static function forget(): void
    {
        Session::forget('auth_user_id');
        Session::forget('auth_fp');
        self::$user = null;
    }

    /** @param array<string,mixed> $user */
    private static function fingerprint(array $user): string
    {
        $key = (string) Config::get('app.key', '');
        if ($key === '') {
            throw new \RuntimeException('APP_KEY is not set. Run: php bin/console.php key:generate');
        }

        return hash_hmac('sha256', $user['password_hash'] . '|' . $user['session_version'], $key);
    }
}
