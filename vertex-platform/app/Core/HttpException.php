<?php

declare(strict_types=1);

namespace Vertex\Core;

use RuntimeException;

/** Thrown to produce a specific HTTP error response (403, 404, 419, 429 ...). */
final class HttpException extends RuntimeException
{
    /** @param array<string,string> $headers */
    public function __construct(public readonly int $status, string $message = '', public readonly array $headers = [])
    {
        parent::__construct($message !== '' ? $message : self::defaultMessage($status), $status);
    }

    public static function defaultMessage(int $status): string
    {
        return match ($status) {
            400 => 'Bad request',
            401 => 'Authentication required',
            403 => 'You do not have permission to do that',
            404 => 'Page not found',
            405 => 'Method not allowed',
            419 => 'Your session expired. Please go back, refresh the page and try again',
            429 => 'Too many requests. Please wait and try again',
            default => 'Something went wrong',
        };
    }
}
