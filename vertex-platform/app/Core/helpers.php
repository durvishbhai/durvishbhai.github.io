<?php

declare(strict_types=1);

use Vertex\Core\Config;
use Vertex\Core\Csrf;
use Vertex\Core\Env;
use Vertex\Core\Session;

if (!function_exists('e')) {
    /** HTML-escape for output. Use on EVERY dynamic value in templates. */
    function e(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (!function_exists('env')) {
    function env(string $key, mixed $default = null): mixed
    {
        return Env::get($key, $default);
    }
}

if (!function_exists('config')) {
    function config(string $key, mixed $default = null): mixed
    {
        return Config::get($key, $default);
    }
}

if (!function_exists('url')) {
    function url(string $path = '/'): string
    {
        return '/' . ltrim($path, '/');
    }
}

if (!function_exists('asset')) {
    /** Public asset URL with a cache-busting query based on file mtime. */
    function asset(string $path): string
    {
        $file = VERTEX_ROOT . '/public/assets/' . ltrim($path, '/');
        $v = is_file($file) ? (string) filemtime($file) : '0';

        return '/assets/' . ltrim($path, '/') . '?v=' . $v;
    }
}

if (!function_exists('csrf_field')) {
    function csrf_field(): string
    {
        return Csrf::field();
    }
}

if (!function_exists('csrf_token')) {
    function csrf_token(): string
    {
        return Csrf::token();
    }
}

if (!function_exists('flash')) {
    function flash(string $key): mixed
    {
        return Session::pullFlash($key);
    }
}

if (!function_exists('old')) {
    /** Re-populate a form field after failed validation (never used for passwords). */
    function old(string $key, string $default = ''): string
    {
        $old = Session::get('_old', []);

        return is_array($old) && isset($old[$key]) && is_string($old[$key]) ? $old[$key] : $default;
    }
}

if (!function_exists('uuid_hex')) {
    function uuid_hex(): string
    {
        return bin2hex(random_bytes(16));
    }
}

if (!function_exists('can')) {
    /** Template convenience only. The server re-checks every action; hiding a link is not security. */
    function can(string $permission): bool
    {
        return \Vertex\Services\Gate::can($permission);
    }
}

if (!function_exists('local_time')) {
    /** Formats a UTC database timestamp in the signed-in user's time zone (falls back to the app time zone). */
    function local_time(?string $utc, string $format = 'd M Y, H:i'): string
    {
        if ($utc === null || $utc === '') {
            return '—';
        }
        $user = \Vertex\Services\Auth::user();
        $tz = (string) ($user['timezone'] ?? '') ?: (string) config('app.timezone', 'UTC');
        try {
            return (new \DateTimeImmutable($utc, new \DateTimeZone('UTC')))->setTimezone(new \DateTimeZone($tz))->format($format);
        } catch (\Exception) {
            return $utc . ' UTC';
        }
    }
}

if (!function_exists('local_date')) {
    /** A calendar date (Y-m-d) for display. Dates are not instants, so no time zone conversion is applied. */
    function local_date(?string $ymd, string $format = 'd M Y'): string
    {
        if ($ymd === null || $ymd === '') {
            return '—';
        }
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $ymd);

        return $d === false ? $ymd : $d->format($format);
    }
}

if (!function_exists('money')) {
    /** Integer paise for display: "₹12,500.50" (negative amounts keep their sign). */
    function money(int|string|null $minor): string
    {
        if ($minor === null || $minor === '') {
            return '—';
        }
        $m = (int) $minor;

        return ($m < 0 ? '-' : '') . '₹' . \Vertex\Services\FieldRules::formatMinor(abs($m));
    }
}

if (!function_exists('status_label')) {
    function status_label(string $status): string
    {
        return ucfirst(str_replace('_', ' ', $status));
    }
}

if (!function_exists('format_minutes')) {
    /** 90 -> "1h 30m". */
    function format_minutes(int|string|null $minutes): string
    {
        $m = (int) $minutes;
        if ($m <= 0) {
            return '0m';
        }

        return ($m >= 60 ? intdiv($m, 60) . 'h' . ($m % 60 > 0 ? ' ' . ($m % 60) . 'm' : '') : $m . 'm');
    }
}
