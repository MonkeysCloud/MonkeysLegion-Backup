<?php

declare(strict_types=1);

namespace MonkeysLegion\Backup\Tests\Unit;

use MonkeysLegion\Backup\Engine\RedisEngine;
use MonkeysLegion\Backup\ValueObject\DumpOptions;
use PHPUnit\Framework\TestCase;

final class RedisEngineTest extends TestCase
{
    private RedisEngine $engine;

    protected function setUp(): void
    {
        $this->engine = new RedisEngine();
    }

    public function testName(): void
    {
        $this->assertSame('redis', $this->engine->name());
    }

    public function testSupportsKnownFeatures(): void
    {
        $this->assertTrue($this->engine->supports('compression'));
        $this->assertFalse($this->engine->supports('unknown'));
    }

    public function testBuildDumpCmd(): void
    {
        $options = new DumpOptions(
            engine: 'redis',
            host: '127.0.0.1',
            port: 6379,
            user: 'backupuser',
            password: 'pwd',
            database: 'db_test'
        );

        $cmd = $this->engine->buildDumpCmd($options, '/tmp/test.rdb');

        $this->assertContains('redis-cli', $cmd);
        $this->assertContains('-h', $cmd);
        $this->assertContains('127.0.0.1', $cmd);
        $this->assertContains('-p', $cmd);
        $this->assertContains('6379', $cmd);
        $this->assertContains('--user', $cmd);
        $this->assertContains('backupuser', $cmd);
        $this->assertContains('--rdb', $cmd);
        $this->assertContains('/tmp/test.rdb', $cmd);

        // Password should not be in the argv command line options (since we pass it via REDISCLI_AUTH)
        foreach ($cmd as $arg) {
            $this->assertStringNotContainsString('pwd', $arg);
        }
    }
}
