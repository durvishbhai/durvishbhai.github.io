<?php

declare(strict_types=1);

namespace Vertex\Services;

use Vertex\Core\Config;
use Vertex\Core\Database;

/**
 * Fixed-window, DB-backed rate limiter (atomic upsert; works across PHP processes on shared hosting).
 * Limits are named in config/auth.php `limits` => [max, windowSeconds]. The subject (ip / email / user id) is hashed.
 */
final class RateLimiter
{
    /** Counts one attempt and returns true if the caller is still within the limit. */
    public static function hit(string $name, string $subject): bool
    {
        [$max, $window] = self::limit($name);
        $key = hash('sha256', mb_strtolower($subject));
        Database::run(
            'INSERT INTO rate_limits (bucket, rkey, hits, window_started_at) VALUES (?, ?, 1, UTC_TIMESTAMP())
             ON DUPLICATE KEY UPDATE
                hits = IF(window_started_at < UTC_TIMESTAMP() - INTERVAL ? SECOND, 1, hits + 1),
                window_started_at = IF(window_started_at < UTC_TIMESTAMP() - INTERVAL ? SECOND, UTC_TIMESTAMP(), window_started_at)',
            [$name, $key, $window, $window],
        );
        $row = Database::one('SELECT hits FROM rate_limits WHERE bucket = ? AND rkey = ?', [$name, $key]);

        if (random_int(1, 100) === 1) {
            Database::run('DELETE FROM rate_limits WHERE window_started_at < UTC_TIMESTAMP() - INTERVAL 2 DAY');
        }

        return (int) $row['hits'] <= $max;
    }

    /** Non-counting check. */
    public static function tooMany(string $name, string $subject): bool
    {
        [$max, $window] = self::limit($name);
        $row = Database::one(
            'SELECT hits FROM rate_limits WHERE bucket = ? AND rkey = ? AND window_started_at >= UTC_TIMESTAMP() - INTERVAL ? SECOND',
            [$name, hash('sha256', mb_strtolower($subject)), $window],
        );

        return $row !== null && (int) $row['hits'] >= $max;
    }

    public static function clear(string $name, string $subject): void
    {
        Database::run('DELETE FROM rate_limits WHERE bucket = ? AND rkey = ?', [$name, hash('sha256', mb_strtolower($subject))]);
    }

    /** @return array{0:int,1:int} */
    private static function limit(string $name): array
    {
        $l = Config::get("auth.limits.{$name}");
        if (!is_array($l)) {
            throw new \LogicException("Unknown rate limit '{$name}'.");
        }

        return [(int) $l[0], (int) $l[1]];
    }
}
