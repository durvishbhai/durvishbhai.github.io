<?php

declare(strict_types=1);

namespace Vertex\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Vertex\Core\Env;

final class EnvTest extends TestCase
{
    private string $file;

    protected function setUp(): void
    {
        Env::reset();
        $this->file = tempnam(sys_get_temp_dir(), 'env');
    }

    protected function tearDown(): void
    {
        @unlink($this->file);
        Env::reset();
    }

    public function testParsesQuotesCommentsAndTypes(): void
    {
        file_put_contents($this->file, <<<'ENV'
# comment
VTX_T_PLAIN=hello
VTX_T_DQ="hello world # not a comment"
VTX_T_SQ='raw $value'
VTX_T_INLINE=value # trailing comment
VTX_T_BOOL=true
VTX_T_OFF=false
VTX_T_EMPTY=
ENV);
        Env::load($this->file);
        $this->assertSame('hello', Env::get('VTX_T_PLAIN'));
        $this->assertSame('hello world # not a comment', Env::get('VTX_T_DQ'));
        $this->assertSame('raw $value', Env::get('VTX_T_SQ'));
        $this->assertSame('value', Env::get('VTX_T_INLINE'));
        $this->assertTrue(Env::get('VTX_T_BOOL'));
        $this->assertFalse(Env::get('VTX_T_OFF'));
        $this->assertSame('fallback', Env::get('VTX_T_EMPTY', 'fallback'));
        $this->assertSame('d', Env::get('VTX_T_MISSING', 'd'));
    }

    public function testRealEnvironmentVariablesWinOverFile(): void
    {
        file_put_contents($this->file, "VTX_T_PRIO=from-file\n");
        putenv('VTX_T_PRIO=from-server');
        Env::load($this->file);
        $this->assertSame('from-server', Env::get('VTX_T_PRIO'));
        putenv('VTX_T_PRIO');
    }

    public function testMissingFileIsIgnored(): void
    {
        Env::load('/nonexistent/.env');
        $this->assertNull(Env::get('VTX_T_NOPE'));
    }
}
