<?php

declare(strict_types=1);

namespace Vertex\Core;

use ErrorException;
use Throwable;

/**
 * Central error handling. Every PHP warning/notice becomes an exception, every uncaught throwable becomes a
 * logged, reference-numbered error page. Production never shows internals; APP_DEBUG=true does.
 */
final class ErrorHandler
{
    public static function register(): void
    {
        error_reporting(E_ALL);
        ini_set('display_errors', '0');
        ini_set('log_errors', '0');

        set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
            if (!(error_reporting() & $severity)) {
                return false;
            }
            throw new ErrorException($message, 0, $severity, $file, $line);
        });

        set_exception_handler(static function (Throwable $e): void {
            self::render($e, PHP_SAPI === 'cli' ? null : Request::capture())->send();
        });

        register_shutdown_function(static function (): void {
            $err = error_get_last();
            if ($err !== null && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
                self::render(new ErrorException($err['message'], 0, $err['type'], $err['file'], $err['line']), null)->send();
            }
        });
    }

    public static function render(Throwable $e, ?Request $request): Response
    {
        $status = $e instanceof HttpException ? $e->status : 500;
        $headers = $e instanceof HttpException ? $e->headers : [];

        if ($status >= 500) {
            Logger::error($e::class . ': ' . $e->getMessage(), ['file' => $e->getFile(), 'line' => $e->getLine(), 'trace' => self::shortTrace($e)]);
        } elseif ($status === 403 || $status === 419 || $status === 429) {
            Logger::security("HTTP {$status}", ['path' => $request->path ?? null, 'ip' => $request?->ip()]);
        }

        $debug = (bool) Config::get('app.debug', false);
        $reference = Request::id();

        if (PHP_SAPI === 'cli') {
            return new Response($status, $e::class . ': ' . $e->getMessage() . "\n");
        }

        if ($request !== null && $request->expectsJson()) {
            $payload = ['error' => $status >= 500 && !$debug ? 'Server error' : $e->getMessage(), 'reference' => $reference];

            return (new Response($status, (string) json_encode($payload), ['Content-Type' => 'application/json; charset=UTF-8'] + $headers));
        }

        $message = $status >= 500 && !$debug ? 'An unexpected error occurred. Our team has been notified.' : $e->getMessage();
        try {
            $html = View::render('errors/error', [
                'status' => $status,
                'message' => $message,
                'reference' => $reference,
                'trace' => $debug ? $e::class . ': ' . $e->getMessage() . "\n" . $e->getTraceAsString() : null,
                'title' => "Error {$status}",
                'noindex' => true,
            ], $status >= 500 ? 'layouts/bare' : 'layouts/public'); // server errors never depend on the database-driven site header
        } catch (Throwable) {
            $html = '<!doctype html><meta charset="utf-8"><title>Error ' . $status . '</title><p>Error ' . $status . '. Reference: ' . htmlspecialchars($reference, ENT_QUOTES) . '</p>';
        }

        return Response::html($html, $status, $headers + SecurityHeadersPolicy::headers());
    }

    private static function shortTrace(Throwable $e): string
    {
        return implode(' | ', array_slice(array_map('trim', explode("\n", $e->getTraceAsString())), 0, 8));
    }
}
