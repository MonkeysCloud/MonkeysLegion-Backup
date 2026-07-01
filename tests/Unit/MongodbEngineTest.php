<?php

declare(strict_types=1);

namespace MonkeysLegion\Backup\Tests\Unit;

use MonkeysLegion\Backup\Engine\EngineName;
use MonkeysLegion\Backup\Engine\MongodbEngine;
use MonkeysLegion\Backup\ValueObject\DumpOptions;
use MonkeysLegion\Backup\ValueObject\RestoreOptions;
use PHPUnit\Framework\TestCase;

final class MongodbEngineTest extends TestCase
{
    private MongodbEngine $engine;

    protected function setUp(): void
    {
        $this->engine = new MongodbEngine();
    }

    public function testName(): void
    {
        $this->assertSame(EngineName::MongoDB, $this->engine->name());
        $this->assertSame('mongodb', $this->engine->name()->value);
    }

    public function testSupportsKnownFeatures(): void
    {
        $this->assertTrue($this->engine->supports('compression'));
        $this->assertFalse($this->engine->supports('unknown'));
    }

    public function testBuildDumpCmd(): void
    {
        $options = new DumpOptions(
            engine: 'mongodb',
            host: '127.0.0.1',
            port: 27017,
            user: 'root',
            password: 'pwd',
            database: 'db_test'
        );

        $cmd = $this->engine->buildDumpCmd($options, '/tmp/test.archive');

        $this->assertContains('mongodump', $cmd);
        $this->assertContains('--archive=/tmp/test.archive', $cmd);
        $this->assertContains('--db=db_test', $cmd);
        $this->assertContains('--host=127.0.0.1', $cmd);
        $this->assertContains('--port=27017', $cmd);
        $this->assertContains('--username=root', $cmd);
        $this->assertContains('--password=pwd', $cmd);
    }

    public function testBuildRestoreCmd(): void
    {
        $options = new RestoreOptions(
            engine: 'mongodb',
            sourcePath: '/tmp/test.archive',
            host: '127.0.0.1',
            port: 27017,
            user: 'root',
            password: 'pwd',
            database: 'db_test'
        );

        $cmd = $this->engine->buildRestoreCmd($options);

        $this->assertContains('mongorestore', $cmd);
        $this->assertContains('--archive=/tmp/test.archive', $cmd);
        $this->assertContains('--db=db_test', $cmd);
        $this->assertContains('--host=127.0.0.1', $cmd);
        $this->assertContains('--port=27017', $cmd);
        $this->assertContains('--username=root', $cmd);
        $this->assertContains('--password=pwd', $cmd);
    }
}
