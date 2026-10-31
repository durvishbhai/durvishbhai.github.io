<?php

declare(strict_types=1);

namespace Vertex\Core;

use RuntimeException;

/** Thrown by services when input is invalid; controllers turn it into form errors. */
final class ValidationException extends RuntimeException
{
    /** @param list<string> $errors */
    public function __construct(public readonly array $errors)
    {
        parent::__construct($errors[0] ?? 'Validation failed.');
    }
}
