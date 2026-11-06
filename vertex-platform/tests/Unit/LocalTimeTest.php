<?php

declare(strict_types=1);

namespace Vertex\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Vertex\Core\Config;

final class LocalTimeTest extends TestCase
{
    protected function setUp(): void
    {
        $_SESSION = [];
    }

    public function testConvertsUtcToAppTimezoneForAnonymousViewers(): void
    {
        Config::set('app.timezone', 'Asia/Kolkata');
        $this->assertSame('01 Jan 2026, 05:30', local_time('2026-01-01 00:00:00'));
        Config::set('app.timezone', 'America/New_York');
        $this->assertSame('31 Dec 2025, 19:00', local_time('2026-01-01 00:00:00'));
        Config::set('app.timezone', 'UTC');
        $this->assertSame('01 Jan 2026, 00:00', local_time('2026-01-01 00:00:00'));
    }

    public function testHandlesNullAndFormats(): void
    {
        Config::set('app.timezone', 'UTC');
        $this->assertSame('—', local_time(null));
        $this->assertSame('—', local_time(''));
        $this->assertSame('2026-07-01', local_time('2026-07-01 23:59:59', 'Y-m-d'));
    }

    public function testBadTimezoneConfigFallsBackToLabelledUtc(): void
    {
        Config::set('app.timezone', 'Not/Real');
        $this->assertSame('2026-01-01 00:00:00 UTC', local_time('2026-01-01 00:00:00'));
        Config::set('app.timezone', 'UTC');
    }
}
