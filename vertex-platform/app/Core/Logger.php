<?php

declare(strict_types=1);

namespace Vertex\Core;

/** Append-only file logger (storage/logs/<channel>-YYYY-MM-DD.log). Never log secrets, passwords or card data. */
final class Logger
{
    /** @param array<string,mixed> $context */
    public static function log(string $channel, string $level, string $message, array $context = []): void
    {
        $dir = VERTEX_ROOT . '/storage/logs';
        if (!is_dir($dir)) {
            @mkdir($dir, 0750, true);
        }
        $line = sprintf(
            "[%s] %s.%s req=%s %s %s\n",
            gmdate('Y-m-d\TH:i:s\Z'),
            $channel,
            strtoupper($level),
            Request::id(),
            str_replace(["\r", "\n"], ' ', $message),
            $context === [] ? '' : json_encode($context, JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR),
        );
        @file_put_contents($dir . '/' . $channel . '-' . gmdate('Y-m-d') . '.log', $line, FILE_APPEND | LOCK_EX);
    }

    /** @param array<string,mixed> $context */
    public static function error(string $message, array $context = []): void
    {
        self::log('app', 'error', $message, $context);
    }

    /** @param array<string,mixed> $context */
    public static function security(string $message, array $context = []): void
    {
        self::log('security', 'warning', $message, $context);
    }
}
