<?php

declare(strict_types=1);

namespace Vertex\Services;

use Vertex\Core\Config;
use Vertex\Core\Database;
use Vertex\Core\HttpException;
use Vertex\Core\Session;
use Vertex\Core\ValidationException;

/**
 * Optional TOTP two-factor login. Rules:
 *  - turning it on needs the account password AND a valid code from the authenticator (proves the app was set up correctly);
 *  - 10 single-use recovery codes are shown once and stored only as hashes;
 *  - a used code (time step) can never be used again; wrong codes are rate limited per user;
 *  - turning it off needs the password and a current code (or a recovery code);
 *  - an Administrator with `users.reset_2fa` can reset it for a lower-ranked user who lost the device (audited, password step-up);
 *  - TWO_FACTOR_REQUIRED_LEVEL (.env, default 0 = off) makes it mandatory for roles at or above that level.
 */
final class TwoFactorService
{
    public const RECOVERY_COUNT = 10;

    /** @param array<string,mixed> $user */
    public static function isEnabled(array $user): bool
    {
        return !empty($user['totp_enabled_at']) && !empty($user['totp_secret_enc']);
    }

    public static function requiredFor(int $userId): bool
    {
        $level = (int) Config::get('auth.two_factor_required_level', 0);

        return $level > 0 && Gate::level($userId) >= $level;
    }

    /** Starts (or restarts) setup: a fresh secret is kept in the session until the user proves they can produce a code. @return array{secret:string,uri:string} */
    public static function begin(int $userId): array
    {
        $user = self::user($userId);
        if (self::isEnabled($user)) {
            throw new ValidationException(['Two-factor login is already on. Turn it off first to set it up again.']);
        }
        $secret = Totp::generateSecret();
        Session::put('twofa_setup', ['uid' => $userId, 'secret' => $secret]);

        return ['secret' => $secret, 'uri' => Totp::uri($secret, (string) $user['email'], (string) Config::get('app.name', 'Vertex'))];
    }

    /** @return array{secret:string,uri:string}|null */
    public static function pendingSetup(int $userId): ?array
    {
        $p = Session::get('twofa_setup');
        if (!is_array($p) || (int) ($p['uid'] ?? 0) !== $userId || !is_string($p['secret'] ?? null)) {
            return null;
        }
        $user = self::user($userId);

        return ['secret' => $p['secret'], 'uri' => Totp::uri($p['secret'], (string) $user['email'], (string) Config::get('app.name', 'Vertex'))];
    }

    /** @return list<string> the recovery codes, shown once */
    public static function enable(int $userId, string $password, string $code): array
    {
        $pending = self::pendingSetup($userId) ?? throw new ValidationException(['Start the setup again: the setup session expired.']);
        if (!Auth::verifyPassword($userId, $password)) {
            throw new ValidationException(['Your password is not correct.']);
        }
        self::throttle($userId);
        $step = Totp::verify($pending['secret'], $code);
        if ($step === null) {
            RateLimiter::hit('totp_user', (string) $userId);
            throw new ValidationException(['That code is not correct. Check the time on your phone and try again.']);
        }
        $codes = self::newRecoveryCodes();
        Database::transaction(function () use ($userId, $pending, $step, $codes): void {
            Database::run('UPDATE users SET totp_secret_enc = ?, totp_enabled_at = UTC_TIMESTAMP(), totp_last_step = ?, updated_at = UTC_TIMESTAMP() WHERE id = ?', [SecretBox::seal($pending['secret']), $step, $userId]);
            self::storeRecoveryCodes($userId, $codes);
            Audit::record('auth.2fa.enabled', 'user', $userId, [], [], [], $userId);
        });
        Session::forget('twofa_setup');
        RateLimiter::clear('totp_user', (string) $userId);

        return $codes;
    }

    public static function disable(int $userId, string $password, string $code): void
    {
        $user = self::user($userId);
        if (!self::isEnabled($user)) {
            return;
        }
        if (self::requiredFor($userId)) {
            throw new ValidationException(['Two-factor login is required for your role and cannot be turned off.']);
        }
        if (!Auth::verifyPassword($userId, $password)) {
            throw new ValidationException(['Your password is not correct.']);
        }
        if (!self::checkSecondFactor($user, $code)) {
            throw new ValidationException(['That code is not correct.']);
        }
        self::clear($userId, $userId, 'auth.2fa.disabled');
    }

    /** @return list<string> */
    public static function regenerateRecoveryCodes(int $userId, string $password, string $code): array
    {
        $user = self::user($userId);
        if (!self::isEnabled($user)) {
            throw new ValidationException(['Two-factor login is not on.']);
        }
        if (!Auth::verifyPassword($userId, $password)) {
            throw new ValidationException(['Your password is not correct.']);
        }
        if (!self::checkSecondFactor($user, $code)) {
            throw new ValidationException(['That code is not correct.']);
        }
        $codes = self::newRecoveryCodes();
        Database::transaction(function () use ($userId, $codes): void {
            Database::run('DELETE FROM user_recovery_codes WHERE user_id = ?', [$userId]);
            self::storeRecoveryCodes($userId, $codes);
            Audit::record('auth.2fa.recovery_regenerated', 'user', $userId, [], [], [], $userId);
        });

        return $codes;
    }

    /** An administrator resets a lower-ranked user's two-factor login after identifying them out of band. */
    public static function adminReset(int $actorId, int $targetId): void
    {
        Gate::authorize('users.reset_2fa', $actorId);
        $target = self::user($targetId);
        if ($targetId === $actorId || Gate::level($targetId) >= Gate::level($actorId)) {
            throw new HttpException(403, 'You cannot reset two-factor login for this account.');
        }
        if (!self::isEnabled($target)) {
            throw new ValidationException(['Two-factor login is not on for this user.']);
        }
        self::clear($targetId, $actorId, 'auth.2fa.reset_by_admin');
        (new \Vertex\Repositories\UserRepository())->bumpSessionVersion($targetId);
    }

    /** Verifies the second factor for a sign-in. @return 'ok'|'invalid'|'locked' */
    public static function verifyLogin(int $userId, string $input): string
    {
        $user = self::user($userId);
        if (RateLimiter::tooMany('totp_user', (string) $userId)) {
            Audit::record('auth.2fa.locked', 'user', $userId, [], [], [], $userId);

            return 'locked';
        }
        if (self::checkSecondFactor($user, $input)) {
            RateLimiter::clear('totp_user', (string) $userId);

            return 'ok';
        }
        RateLimiter::hit('totp_user', (string) $userId);
        Audit::record('auth.2fa.failed', 'user', $userId, [], [], [], $userId);

        return 'invalid';
    }

    public static function remainingRecoveryCodes(int $userId): int
    {
        return (int) Database::one('SELECT COUNT(*) AS c FROM user_recovery_codes WHERE user_id = ? AND used_at IS NULL', [$userId])['c'];
    }

    /** @param array<string,mixed> $user a TOTP code (no step reuse) or an unused recovery code */
    private static function checkSecondFactor(array $user, string $input): bool
    {
        $input = trim($input);
        $secret = SecretBox::open((string) $user['totp_secret_enc']);
        if ($secret !== null) {
            $step = Totp::verify($secret, $input);
            if ($step !== null) {
                // Compare-and-set: two parallel requests with the same code cannot both succeed.
                $upd = Database::run('UPDATE users SET totp_last_step = ? WHERE id = ? AND (totp_last_step IS NULL OR totp_last_step < ?)', [$step, $user['id'], $step]);

                return $upd->rowCount() === 1;
            }
        }
        $norm = strtolower(preg_replace('/[\s-]/', '', $input) ?? '');
        if (preg_match('/^[a-z0-9]{10}$/', $norm)) {
            $upd = Database::run('UPDATE user_recovery_codes SET used_at = UTC_TIMESTAMP() WHERE user_id = ? AND code_hash = ? AND used_at IS NULL', [$user['id'], hash('sha256', $norm)]);
            if ($upd->rowCount() === 1) {
                Audit::record('auth.2fa.recovery_used', 'user', (int) $user['id'], [], [], [], (int) $user['id']);

                return true;
            }
        }

        return false;
    }

    private static function clear(int $userId, int $actorId, string $audit): void
    {
        Database::transaction(function () use ($userId, $actorId, $audit): void {
            Database::run('UPDATE users SET totp_secret_enc = NULL, totp_enabled_at = NULL, totp_last_step = NULL, updated_at = UTC_TIMESTAMP() WHERE id = ?', [$userId]);
            Database::run('DELETE FROM user_recovery_codes WHERE user_id = ?', [$userId]);
            Audit::record($audit, 'user', $userId, [], [], ['by' => $actorId], $actorId);
        });
    }

    private static function throttle(int $userId): void
    {
        if (RateLimiter::tooMany('totp_user', (string) $userId)) {
            throw new ValidationException(['Too many wrong codes. Wait ten minutes and try again.']);
        }
    }

    /** @return list<string> */
    private static function newRecoveryCodes(): array
    {
        $codes = [];
        while (count($codes) < self::RECOVERY_COUNT) {
            $codes[] = substr(strtolower(strtr(base64_encode(random_bytes(10)), ['+' => '', '/' => '', '=' => ''])), 0, 10);
            $codes = array_values(array_unique(array_filter($codes, static fn (string $c): bool => strlen($c) === 10)));
        }

        return $codes;
    }

    /** @param list<string> $codes */
    private static function storeRecoveryCodes(int $userId, array $codes): void
    {
        foreach ($codes as $c) {
            Database::run('INSERT INTO user_recovery_codes (user_id, code_hash, created_at) VALUES (?, ?, UTC_TIMESTAMP())', [$userId, hash('sha256', $c)]);
        }
    }

    /** @return array<string,mixed> */
    private static function user(int $userId): array
    {
        return Database::one('SELECT * FROM users WHERE id = ?', [$userId]) ?? throw new HttpException(404);
    }
}
