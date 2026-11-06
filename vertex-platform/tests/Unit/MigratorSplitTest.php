<?php

declare(strict_types=1);

namespace Vertex\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Vertex\Services\Migrator;

final class MigratorSplitTest extends TestCase
{
    public function testSplitsStatementsAndDropsCommentLines(): void
    {
        $sql = "-- header\nCREATE TABLE a (\n  id INT -- inline\n);\n\n-- note\nINSERT INTO a VALUES (1);\n";
        $parts = (new Migrator('/tmp'))->split($sql);
        $this->assertCount(2, $parts);
        $this->assertStringStartsWith('CREATE TABLE a', $parts[0]);
        $this->assertStringStartsWith('INSERT INTO a', $parts[1]);
    }
}
