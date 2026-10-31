<?php

declare(strict_types=1);

namespace Vertex\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Vertex\Services\PasswordPolicy;

final class PasswordPolicyTest extends TestCase
{
    public function testAcceptsLongUnguessablePassword(): void
    {
        $this->assertSame([], PasswordPolicy::check('correct-horse-battery-staple', 'jane@example.test', 'Jane Doe'));
    }

    public function testRejectsShortCommonRepeatedAndPersonalPasswords(): void
    {
        $this->assertNotEmpty(PasswordPolicy::check('short1!'));
        $this->assertNotEmpty(PasswordPolicy::check('password123'));
        $this->assertNotEmpty(PasswordPolicy::check('aaaaaaaaaaaaaaaa'));
        $this->assertNotEmpty(PasswordPolicy::check('janedoe-is-great-2026', 'janedoe@example.test'));
        $this->assertNotEmpty(PasswordPolicy::check('my-name-is-JaneDoe-ok', '', 'Jane Doe'));
    }

    public function testRejectsAbsurdLength(): void
    {
        $this->assertNotEmpty(PasswordPolicy::check(str_repeat('x7Q', 100)));
    }
}
