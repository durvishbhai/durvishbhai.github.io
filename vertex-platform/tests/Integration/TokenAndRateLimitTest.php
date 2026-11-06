<?php

declare(strict_types=1);

namespace Vertex\Tests\Integration;

use Vertex\Core\Database;
use Vertex\Services\RateLimiter;
use Vertex\Services\TokenService;

final class TokenAndRateLimitTest extends DatabaseTestCase
{
    public function testOnlyTheHashOfATokenIsStored(): void
    {
        $id = $this->makeUser('t@x.test', ['client']);
        $raw = TokenService::issue($id, TokenService::RESET, 60);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $raw);
        $row = Database::one('SELECT token_hash FROM auth_tokens WHERE user_id = ?', [$id]);
        $this->assertSame(hash('sha256', $raw), $row['token_hash']);
        $this->assertNotSame($raw, $row['token_hash']);
        $this->assertSame(0, (int) Database::one('SELECT COUNT(*) c FROM auth_tokens WHERE token_hash = ?', [$raw])['c']);
    }

    public function testConsumeIsAtomicSingleUse(): void
    {
        $id = $this->makeUser('t@x.test', ['client']);
        $raw = TokenService::issue($id, TokenService::VERIFY, 60);
        $peek = TokenService::peek($raw, [TokenService::VERIFY]);
        $this->assertNotNull($peek);
        // Two requests that both passed peek() before either consumed (race): only one may win.
        $this->assertTrue(TokenService::consume($peek['id']));
        $this->assertFalse(TokenService::consume($peek['id']));
        $this->assertNull(TokenService::peek($raw, [TokenService::VERIFY]));
    }

    public function testPeekRejectsWrongPurposeExpiredAndMalformedTokens(): void
    {
        $id = $this->makeUser('t@x.test', ['client']);
        $raw = TokenService::issue($id, TokenService::RESET, 60);
        $this->assertNull(TokenService::peek($raw, [TokenService::VERIFY]), 'a reset token cannot verify email');
        $this->assertNull(TokenService::peek($raw, [TokenService::INVITE]));
        $this->assertNotNull(TokenService::peek($raw, [TokenService::VERIFY, TokenService::RESET]));
        foreach (['', 'abc', strtoupper($raw), $raw . 'x', "{$raw}' OR '1'='1", substr($raw, 1)] as $bad) {
            $this->assertNull(TokenService::peek($bad, [TokenService::RESET]), "malformed: {$bad}");
        }
        Database::run('UPDATE auth_tokens SET expires_at = UTC_TIMESTAMP() - INTERVAL 1 SECOND');
        $this->assertNull(TokenService::peek($raw, [TokenService::RESET]), 'expired');
    }

    public function testIssuingANewTokenInvalidatesTheOldOneForThatPurposeOnly(): void
    {
        $id = $this->makeUser('t@x.test', ['client']);
        $verify = TokenService::issue($id, TokenService::VERIFY, 60);
        $first = TokenService::issue($id, TokenService::RESET, 60);
        $second = TokenService::issue($id, TokenService::RESET, 60);
        $this->assertNull(TokenService::peek($first, [TokenService::RESET]));
        $this->assertNotNull(TokenService::peek($second, [TokenService::RESET]));
        $this->assertNotNull(TokenService::peek($verify, [TokenService::VERIFY]));
    }

    public function testRateLimiterAllowsUpToTheLimitThenBlocksAndIsPerSubject(): void
    {
        [$max] = \Vertex\Core\Config::get('auth.limits.password_reset_email');
        for ($i = 1; $i <= $max; $i++) {
            $this->assertTrue(RateLimiter::hit('password_reset_email', 'a@x.test'), "hit {$i}");
        }
        $this->assertFalse(RateLimiter::hit('password_reset_email', 'a@x.test'));
        $this->assertTrue(RateLimiter::tooMany('password_reset_email', 'A@X.test'), 'case-insensitive subject');
        $this->assertTrue(RateLimiter::hit('password_reset_email', 'b@x.test'), 'other subjects unaffected');
        $this->assertTrue(RateLimiter::hit('register_ip', 'a@x.test'), 'other buckets unaffected');
    }

    public function testRateLimitWindowResets(): void
    {
        [$max] = \Vertex\Core\Config::get('auth.limits.register_ip');
        for ($i = 0; $i <= $max; $i++) {
            RateLimiter::hit('register_ip', '1.2.3.4');
        }
        $this->assertTrue(RateLimiter::tooMany('register_ip', '1.2.3.4'));
        Database::run("UPDATE rate_limits SET window_started_at = UTC_TIMESTAMP() - INTERVAL 2 HOUR WHERE bucket = 'register_ip'");
        $this->assertFalse(RateLimiter::tooMany('register_ip', '1.2.3.4'));
        $this->assertTrue(RateLimiter::hit('register_ip', '1.2.3.4'), 'new window starts at 1');
        $this->assertSame(1, (int) Database::one("SELECT hits FROM rate_limits WHERE bucket = 'register_ip'")['hits']);
    }

    public function testClearResetsTheCounter(): void
    {
        RateLimiter::hit('stepup_user', '42');
        RateLimiter::clear('stepup_user', '42');
        $this->assertSame(0, (int) Database::one("SELECT COUNT(*) c FROM rate_limits WHERE bucket = 'stepup_user'")['c']);
    }

    public function testUnknownLimitNameIsAProgrammerError(): void
    {
        $this->expectException(\LogicException::class);
        RateLimiter::hit('nonexistent_limit', 'x');
    }
}
