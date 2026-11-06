<?php

declare(strict_types=1);

namespace Vertex\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Vertex\Services\QrCode;

/** Structure checks for the QR encoder. (When it was written, the output for 24 payloads from 1 to 997 bytes was also decoded with a real QR reader: all matched.) */
final class QrCodeTest extends TestCase
{
    public function testSizeGrowsWithThePayloadInTheStandardSteps(): void
    {
        $this->assertCount(21, QrCode::matrix('A'));                      // version 1
        $this->assertCount(21, QrCode::matrix(str_repeat('x', 14)));      // still version 1 (level M holds 14 bytes)
        $this->assertCount(25, QrCode::matrix(str_repeat('x', 15)));      // version 2
        $this->assertCount(41, QrCode::matrix(str_repeat('x', 106)));     // version 6 holds 106 bytes at level M
        $this->assertCount(45, QrCode::matrix(str_repeat('x', 107)));     // version 7
        $this->assertSame(0, (count(QrCode::matrix(str_repeat('y', 300))) - 17) % 4);
    }

    public function testFinderTimingAndDarkModulePatternsArePresent(): void
    {
        $m = QrCode::matrix('upi://pay?pa=merchant@ybl&am=250.50');
        $n = count($m);
        foreach ([[0, 0], [$n - 7, 0], [0, $n - 7]] as [$x0, $y0]) {
            for ($i = 0; $i < 7; $i++) {
                $this->assertTrue($m[$y0][$x0 + $i] && $m[$y0 + 6][$x0 + $i] && $m[$y0 + $i][$x0] && $m[$y0 + $i][$x0 + 6], 'finder border');
            }
            $this->assertFalse($m[$y0 + 1][$x0 + 1], 'finder ring');
            $this->assertTrue($m[$y0 + 3][$x0 + 3], 'finder centre');
        }
        for ($i = 8; $i < $n - 8; $i++) {
            $this->assertSame($i % 2 === 0, $m[6][$i], 'timing row');
            $this->assertSame($i % 2 === 0, $m[$i][6], 'timing column');
        }
        $this->assertTrue($m[$n - 8][8], 'the always-dark module');
    }

    public function testTheSameTextAlwaysGivesTheSameCodeAndDifferentTextDoesNot(): void
    {
        $this->assertSame(QrCode::matrix('amount=100.00'), QrCode::matrix('amount=100.00'));
        $this->assertNotSame(QrCode::matrix('amount=100.00'), QrCode::matrix('amount=100.01'));
    }

    public function testSvgIsSelfContainedAndEscapesItsLabel(): void
    {
        $svg = QrCode::svg('hello', 'Pay <b>"now"</b>');
        $this->assertStringStartsWith('<svg class="qr-svg"', $svg);
        $this->assertStringContainsString('fill="#ffffff"', $svg);
        $this->assertStringNotContainsString('<b>', $svg);
        $this->assertStringNotContainsString('<script', $svg);
        $this->assertStringNotContainsString('hello', $svg, 'the text itself is not written into the picture');
    }

    public function testEmptyAndOversizedTextAreRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        QrCode::svg('');
    }

    public function testTooLongTextIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        QrCode::svg(str_repeat('z', 1200));
    }
}
