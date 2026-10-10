<?php

declare(strict_types=1);

namespace Vertex\Services;

use Vertex\Core\Config;
use Vertex\Core\Database;

/** DB-backed login throttle (works across PHP processes on shared hosting). */
final class LoginThrottle
{
    public function isLocked(string $email, string $ip): bool
    {
        $max = (int) Config::get('session.login_max_attempts', 5);
        $window = (int) Config::get('session.login_lockout_minutes', 15);
        $since = gmdate('Y-m-d H:i:s', time() - $window * 60);

        $byAccount = Database::one(
            'SELECT COUNT(*) AS c FROM login_attempts WHERE email = ? AND ip = ? AND succeeded = 0 AND created_at >= ?',
            [$email, $ip, $since],
        );
        // Broader per-IP ceiling to slow credential stuffing across many emails.
        $byIp = Database::one(
            'SELECT COUNT(*) AS c FROM login_attempts WHERE ip = ? AND succeeded = 0 AND created_at >= ?',
            [$ip, $since],
        );

        return (int) $byAccount['c'] >= $max || (int) $byIp['c'] >= $max * 4;
    }

    public function record(string $email, string $ip, bool $succeeded): void
    {
        Database::run(
            'INSERT INTO login_attempts (email, ip, succeeded, created_at) VALUES (?, ?, ?, UTC_TIMESTAMP())',
            [substr($email, 0, 190), $ip, $succeeded ? 1 : 0],
        );
        if ($succeeded) {
            // A successful login clears this account's failure streak from this IP.
            Database::run('DELETE FROM login_attempts WHERE email = ? AND ip = ? AND succeeded = 0', [$email, $ip]);
        }
        // Opportunistic pruning keeps the table small.
        if (random_int(1, 50) === 1) {
            Database::run('DELETE FROM login_attempts WHERE created_at < ?', [gmdate('Y-m-d H:i:s', time() - 86400 * 7)]);
        }
    }
}
