<?php

declare(strict_types=1);

namespace Vertex\Services;

use Vertex\Core\Config;

/** Length-first password policy (NIST style): long, not obviously guessable. No composition rules. */
final class PasswordPolicy
{
    private const COMMON = [
        'password', 'password1', 'password123', '123456789012', 'qwertyuiop12', 'letmein12345',
        'administrator', 'welcome12345', 'iloveyou1234', 'changeme1234', 'vertex123456', 'abc123abc123',
    ];

    /** @return list<string> human-readable problems, empty when acceptable */
    public static function check(string $password, string $email = '', string $name = ''): array
    {
        $min = (int) Config::get('auth.password_min_length', 12);
        $problems = [];
        $len = mb_strlen($password);
        if ($len < $min) {
            $problems[] = "Password must be at least {$min} characters.";
        }
        if ($len > 200) {
            $problems[] = 'Password must be at most 200 characters.';
        }
        $lower = mb_strtolower($password);
        if (in_array($lower, self::COMMON, true) || preg_match('/^(.)\1+$/u', $password)) {
            $problems[] = 'That password is too common.';
        }
        if ($email !== '') {
            $local = mb_strtolower(explode('@', $email)[0]);
            if (mb_strlen($local) >= 4 && str_contains($lower, $local)) {
                $problems[] = 'Password must not contain your email name.';
            }
        }
        if (mb_strlen($name) >= 4 && str_contains($lower, mb_strtolower(str_replace(' ', '', $name)))) {
            $problems[] = 'Password must not contain your name.';
        }

        return $problems;
    }
}
