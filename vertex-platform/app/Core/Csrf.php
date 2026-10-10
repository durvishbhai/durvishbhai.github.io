<?php

declare(strict_types=1);

namespace Vertex\Core;

/** Synchroniser-token CSRF protection tied to the session. */
final class Csrf
{
    public const FIELD = '_token';
    public const HEADER = 'X-CSRF-TOKEN';

    public static function token(): string
    {
        $t = Session::get('_csrf');
        if (!is_string($t) || strlen($t) < 32) {
            $t = bin2hex(random_bytes(32));
            Session::put('_csrf', $t);
        }

        return $t;
    }

    public static function field(): string
    {
        return '<input type="hidden" name="' . self::FIELD . '" value="' . htmlspecialchars(self::token(), ENT_QUOTES, 'UTF-8') . '">';
    }

    public static function verify(Request $request): bool
    {
        $expected = Session::get('_csrf');
        if (!is_string($expected) || $expected === '') {
            return false;
        }
        $given = $request->body[self::FIELD] ?? $request->header(self::HEADER) ?? '';

        return is_string($given) && $given !== '' && hash_equals($expected, $given);
    }

    public static function rotate(): void
    {
        Session::forget('_csrf');
    }
}
