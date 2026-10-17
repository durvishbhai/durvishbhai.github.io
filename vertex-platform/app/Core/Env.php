<?php

declare(strict_types=1);

namespace Vertex\Core;

/**
 * Minimal .env loader (no dependency, works on shared hosting).
 * Real process/server environment variables win over the .env file.
 */
final class Env
{
    /** @var array<string,string> */
    private static array $vars = [];

    public static function load(string $file): void
    {
        if (!is_file($file) || !is_readable($file)) {
            return;
        }
        $lines = file($file, FILE_IGNORE_NEW_LINES) ?: [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') {
                continue;
            }
            if (!preg_match('/^([A-Za-z_][A-Za-z0-9_]*)\s*=\s*(.*)$/', $line, $m)) {
                continue;
            }
            self::$vars[$m[1]] = self::parseValue($m[2]);
        }
    }

    public static function set(string $key, string $value): void
    {
        self::$vars[$key] = $value;
    }

    public static function reset(): void
    {
        self::$vars = [];
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $value = getenv($key);
        if ($value === false) {
            $value = self::$vars[$key] ?? null;
        }
        if ($value === null) {
            return $default;
        }

        return match (strtolower((string) $value)) {
            'true', '(true)' => true,
            'false', '(false)' => false,
            'null', '(null)' => null,
            default => $value === '' ? $default : $value,
        };
    }

    private static function parseValue(string $raw): string
    {
        $raw = trim($raw);
        if ($raw !== '' && $raw[0] === '"' && preg_match('/^"((?:[^"\\\\]|\\\\.)*)"/', $raw, $m)) {
            return stripcslashes($m[1]);
        }
        if ($raw !== '' && $raw[0] === "'" && preg_match("/^'([^']*)'/", $raw, $m)) {
            return $m[1];
        }

        return trim((string) preg_replace('/\s+#.*$/', '', $raw));
    }
}
