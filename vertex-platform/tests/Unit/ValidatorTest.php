<?php

declare(strict_types=1);

namespace Vertex\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Vertex\Core\Validator;

final class ValidatorTest extends TestCase
{
    public function testRequiredAndEmail(): void
    {
        $v = Validator::make(['email' => 'not-an-email', 'name' => '   '], ['email' => 'required|email', 'name' => 'required']);
        $this->assertTrue($v->fails());
        $this->assertArrayHasKey('email', $v->errors());
        $this->assertSame('Name is required.', $v->first('name'));
    }

    public function testValidDataPassesAndIsTrimmed(): void
    {
        $v = Validator::make(['email' => '  a@b.co ', 'qty' => '5'], ['email' => 'required|email', 'qty' => 'required|integer|in:1,5,10']);
        $this->assertFalse($v->fails());
        $this->assertSame(['email' => 'a@b.co', 'qty' => '5'], $v->validated());
    }

    public function testLengthRulesAreMultibyteSafe(): void
    {
        $this->assertFalse(Validator::make(['n' => 'ééééé'], ['n' => 'max:5'])->fails());
        $this->assertTrue(Validator::make(['n' => 'éééééé'], ['n' => 'max:5'])->fails());
        $this->assertTrue(Validator::make(['n' => 'abc'], ['n' => 'min:4'])->fails());
    }

    public function testOptionalEmptyFieldSkipsRules(): void
    {
        $v = Validator::make(['phone' => ''], ['phone' => 'email']);
        $this->assertFalse($v->fails());
    }

    public function testArrayInputCannotBypassScalarRules(): void
    {
        $this->assertTrue(Validator::make(['email' => ['a@b.co']], ['email' => 'required|email'])->fails());
        $this->assertTrue(Validator::make(['x' => ['1']], ['x' => 'in:1'])->fails());
    }

    public function testConfirmed(): void
    {
        $this->assertTrue(Validator::make(['p' => 'abc', 'p_confirmation' => 'abd'], ['p' => 'confirmed'])->fails());
        $this->assertFalse(Validator::make(['p' => 'abc', 'p_confirmation' => 'abc'], ['p' => 'confirmed'])->fails());
    }

    public function testUnknownRuleIsAProgrammerError(): void
    {
        $this->expectException(\LogicException::class);
        Validator::make(['a' => 'x'], ['a' => 'bogus']);
    }
}
