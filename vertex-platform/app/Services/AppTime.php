<?php

declare(strict_types=1);

namespace Vertex\Services;

use Vertex\Core\Config;

/**
 * One place for "what day is it for the business". Timestamps are stored in UTC; calendar dates (deadlines, follow-ups,
 * milestone targets) are plain DATEs interpreted in the configured application time zone (APP_TIMEZONE).
 */
final class AppTime
{
    private static ?\DateTimeImmutable $frozen = null;

    public static function zone(): \DateTimeZone
    {
        try {
            return new \DateTimeZone((string) Config::get('app.timezone', 'UTC'));
        } catch (\Exception) {
            return new \DateTimeZone('UTC');
        }
    }

    public static function now(): \DateTimeImmutable
    {
        return (self::$frozen ?? new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->setTimezone(self::zone());
    }

    /** Today's date (Y-m-d) in the application time zone. */
    public static function today(): string
    {
        return self::now()->format('Y-m-d');
    }

    public static function todayPlus(int $days): string
    {
        return self::now()->modify(($days >= 0 ? '+' : '') . $days . ' day')->format('Y-m-d');
    }

    /** Test hook: pretend "now" is the given UTC instant. Pass null to unfreeze. */
    public static function freeze(?string $utc): void
    {
        self::$frozen = $utc === null ? null : new \DateTimeImmutable($utc, new \DateTimeZone('UTC'));
    }
}
