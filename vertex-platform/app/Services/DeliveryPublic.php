<?php

declare(strict_types=1);

namespace Vertex\Services;

use Vertex\Core\Config;
use Vertex\Core\Database;
use Vertex\Core\Session;
use Vertex\Core\ValidationException;

/**
 * Booking without signing in. A project ID and a client name are not secrets, so they never unlock anything by themselves:
 *   otp    the visitor enters the project ID and the client's name; if they match, a 6-digit code goes to the client's REGISTERED email address. The answer is the same
 *          whether or not the details matched, so nobody can use the page to find out which projects or names exist. Only the right code opens the booking page.
 *   token  the team creates a private link for one project (the token is shown once and only its hash is stored).
 * Config `delivery.public_mode`: off | otp | token | both. After either check, a short-lived grant for that one project is kept in the visitor's session.
 */
final class DeliveryPublic
{
    public static function mode(): string
    {
        $m = (string) Config::get('delivery.public_mode', 'otp');

        return in_array($m, ['off', 'otp', 'token', 'both'], true) ? $m : 'off';
    }

    public static function otpAllowed(): bool
    {
        return in_array(self::mode(), ['otp', 'both'], true);
    }

    public static function tokenAllowed(): bool
    {
        return in_array(self::mode(), ['token', 'both'], true);
    }

    private static function norm(string $s): string
    {
        $s = mb_strtolower(trim($s));
        $s = (string) preg_replace('/[^\p{L}\p{N}]+/u', ' ', $s);

        return trim($s);
    }

    /** @return array{project:array<string,mixed>,client:array<string,mixed>}|null */
    private static function match(string $code, string $name): ?array
    {
        $code = strtoupper(trim($code));
        $n = self::norm($name);
        if ($code === '' || mb_strlen($code) > 30 || $n === '') {
            return null;
        }
        $p = Database::one('SELECT p.id FROM projects p WHERE p.code = ?', [$code]);
        if ($p === null) {
            return null;
        }
        $project = ProjectPolicy::findById((int) $p['id']);
        $client = Database::one('SELECT * FROM clients WHERE id = ?', [$project['client_id'] ?? 0]);
        if ($project === null || $client === null || $client['email'] === null || $client['email'] === '') {
            return null;
        }
        if (self::norm((string) $client['contact_name']) !== $n && self::norm((string) ($client['company_name'] ?? '')) !== $n) {
            return null;
        }

        return ['project' => $project, 'client' => $client];
    }

    private static function hash(string $code, int $projectId): string
    {
        return hash_hmac('sha256', $projectId . ':' . $code, (string) Config::get('app.key', 'delivery'));
    }

    /**
     * Sends a code if (and only if) the details match a project that has delivery booking set up. Always returns normally: the caller shows one message.
     */
    public static function start(string $code, string $name, string $ip): void
    {
        if (!self::otpAllowed()) {
            return;
        }
        $m = self::match($code, $name);
        if ($m === null || DeliveryService::settingsRow((int) $m['project']['id']) === null) {
            return;
        }
        $pid = (int) $m['project']['id'];
        if ((int) Database::one('SELECT COUNT(*) AS c FROM delivery_otps WHERE project_id = ? AND created_at > UTC_TIMESTAMP() - INTERVAL 15 MINUTE', [$pid])['c'] >= 3) {
            return; // enough codes are already out; the visitor can use the latest one
        }
        $minutes = max(1, (int) Config::get('delivery.otp_minutes', 10));
        $otp = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        Database::run('UPDATE delivery_otps SET used_at = UTC_TIMESTAMP() WHERE project_id = ? AND used_at IS NULL', [$pid]); // older codes stop working
        Database::run('INSERT INTO delivery_otps (project_id, code_hash, expires_at, ip, created_at) VALUES (?, ?, UTC_TIMESTAMP() + INTERVAL ? MINUTE, ?, UTC_TIMESTAMP())', [$pid, self::hash($otp, $pid), $minutes, substr($ip, 0, 45)]);
        DeliveryMail::otp((string) $m['client']['email'], (string) $m['client']['contact_name'], $otp, $minutes);
    }

    /** Checks the code. On success a grant for this project is stored in the session. @return bool */
    public static function verify(string $code, string $name, string $otp): bool
    {
        $m = self::match($code, $name);
        $otp = trim($otp);
        if ($m === null || !preg_match('/^\d{6}$/', $otp)) {
            return false;
        }
        $pid = (int) $m['project']['id'];
        $ok = Database::transaction(function () use ($pid, $otp): bool {
            $row = Database::one('SELECT * FROM delivery_otps WHERE project_id = ? AND used_at IS NULL AND expires_at > UTC_TIMESTAMP() ORDER BY id DESC LIMIT 1 FOR UPDATE', [$pid]);
            if ($row === null || (int) $row['attempts'] >= max(1, (int) Config::get('delivery.otp_attempts', 5))) {
                return false;
            }
            if (!hash_equals((string) $row['code_hash'], self::hash($otp, $pid))) {
                Database::run('UPDATE delivery_otps SET attempts = attempts + 1 WHERE id = ?', [$row['id']]);

                return false;
            }
            Database::run('UPDATE delivery_otps SET used_at = UTC_TIMESTAMP() WHERE id = ?', [$row['id']]);

            return true;
        });
        if ($ok) {
            self::grant($pid, 'otp');
        }

        return $ok;
    }

    private static function grant(int $projectId, string $via): void
    {
        Session::regenerate();
        Session::put('delivery_grant', ['project' => $projectId, 'via' => $via, 'until' => time() + max(5, (int) Config::get('delivery.grant_minutes', 30)) * 60]);
    }

    /** The project the visitor is allowed to book for, or null. @return array<string,mixed>|null */
    public static function granted(): ?array
    {
        $g = Session::get('delivery_grant');
        if (!is_array($g) || (int) ($g['until'] ?? 0) < time() || (int) ($g['project'] ?? 0) <= 0) {
            return null;
        }
        if (($g['via'] ?? '') === 'otp' ? !self::otpAllowed() : !self::tokenAllowed()) {
            return null; // the team switched the method off
        }

        return ProjectPolicy::findById((int) $g['project']);
    }

    public static function forget(): void
    {
        Session::forget('delivery_grant');
    }

    // ------------------------------------------------------------------------------------------------ private links

    /** Creates a link token for a project. The token is returned once; only its hash is stored. */
    public static function createToken(int $actorId, array $project): string
    {
        Gate::authorize('delivery.configure', $actorId);
        if (DeliveryService::settingsRow((int) $project['id']) === null) {
            throw new ValidationException(['Set the preparation period of this project first.']);
        }
        $token = bin2hex(random_bytes(24));
        $days = max(1, (int) Config::get('delivery.token_days', 30));
        Database::transaction(function () use ($actorId, $project, $token, $days): void {
            Database::run('INSERT INTO delivery_booking_tokens (project_id, token_hash, expires_at, created_by, created_at) VALUES (?, ?, UTC_TIMESTAMP() + INTERVAL ? DAY, ?, UTC_TIMESTAMP())', [$project['id'], hash('sha256', $token), $days, $actorId]);
            DeliveryService::log(null, (int) $project['id'], null, 'link_created', null, 'valid ' . $days . ' days', $actorId, null);
            Audit::record('delivery.link_created', 'project', (int) $project['id'], [], [], [], $actorId);
        });

        return $token;
    }

    public static function revokeTokens(int $actorId, array $project): int
    {
        Gate::authorize('delivery.configure', $actorId);
        $n = Database::run('UPDATE delivery_booking_tokens SET revoked_at = UTC_TIMESTAMP() WHERE project_id = ? AND revoked_at IS NULL', [$project['id']])->rowCount();
        DeliveryService::log(null, (int) $project['id'], null, 'links_revoked', null, (string) $n, $actorId, null);
        Audit::record('delivery.links_revoked', 'project', (int) $project['id'], [], [], [], $actorId);

        return $n;
    }

    /** @return int how many links of the project are still valid */
    public static function liveTokens(int $projectId): int
    {
        return (int) Database::one('SELECT COUNT(*) AS c FROM delivery_booking_tokens WHERE project_id = ? AND revoked_at IS NULL AND expires_at > UTC_TIMESTAMP()', [$projectId])['c'];
    }

    /** Opens the booking for the project a valid link belongs to. */
    public static function useToken(string $token): bool
    {
        if (!self::tokenAllowed() || !preg_match('/^[a-f0-9]{48}$/', $token)) {
            return false;
        }
        $row = Database::one('SELECT * FROM delivery_booking_tokens WHERE token_hash = ? AND revoked_at IS NULL AND expires_at > UTC_TIMESTAMP()', [hash('sha256', $token)]);
        if ($row === null) {
            return false;
        }
        Database::run('UPDATE delivery_booking_tokens SET last_used_at = UTC_TIMESTAMP() WHERE id = ?', [$row['id']]);
        self::grant((int) $row['project_id'], 'token');

        return true;
    }
}
