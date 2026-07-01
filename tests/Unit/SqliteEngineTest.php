<?php

declare(strict_types=1);

namespace MonkeysLegion\Backup\Tests\Unit;

use MonkeysLegion\Backup\Engine\SqliteEngine;
use MonkeysLegion\Backup\ValueObject\DumpOptions;
use MonkeysLegion\Backup\ValueObject\RestoreOptions;
use PHPUnit\Framework\TestCase;

final class SqliteEngineTest extends TestCase
{
    private SqliteEngine $engine;

    protected function setUp(): void
    {
        $this->engine = new SqliteEngine();
    }

    public function testName(): void
    {
        $this->assertSame('sqlite', $this->engine->name());
    }

    public function testSupportsKnownFeatures(): void
    {
        $this->assertTrue($this->engine->supports('compression'));
        $this->assertFalse($this->engine->supports('unknown'));
    }
}
