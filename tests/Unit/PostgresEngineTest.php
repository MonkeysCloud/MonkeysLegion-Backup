<?php

declare(strict_types=1);

namespace MonkeysLegion\Backup\Tests\Unit;

use MonkeysLegion\Backup\Engine\EngineName;
use MonkeysLegion\Backup\Engine\PostgresEngine;
use MonkeysLegion\Backup\ValueObject\DumpOptions;
use MonkeysLegion\Backup\ValueObject\RestoreOptions;
use PHPUnit\Framework\TestCase;

final class PostgresEngineTest extends TestCase
{
    private PostgresEngine $engine;

    protected function setUp(): void
    {
        $this->engine = new PostgresEngine();
    }

    public function testName(): void
    {
        $this->assertSame(EngineName::Postgres, $this->engine->name());
        $this->assertSame('postgres', $this->engine->name()->value);
    }

    public function testSupportsKnownFeatures(): void
    {
        $this->assertTrue($this->engine->supports('format-custom'));
        $this->assertTrue($this->engine->supports('format-plain'));
        $this->assertTrue($this->engine->supports('compression'));
        $this->assertFalse($this->engine->supports('unknown'));
    }

    public function testBuildDumpCmdPlainDefault(): void
    {
        $options = new DumpOptions(
            engine: 'postgres',
            host: '127.0.0.1',
            port: 5432,
            user: 'postgres',
            password: 'pwd',
            database: 'db_test'
        );

        $cmd = $this->engine->buildDumpCmd($options, '/tmp/test.sql');

        $this->assertContains('pg_dump', $cmd);
        $this->assertContains('--host=127.0.0.1', $cmd);
        $this->assertContains('--port=5432', $cmd);
        $this->assertContains('--username=postgres', $cmd);
        $this->assertContains('--no-password', $cmd);
        $this->assertContains('--format=plain', $cmd);
        $this->assertContains('--file=/tmp/test.sql', $cmd);
        $this->assertContains('db_test', $cmd);

        // Password must not be in cmd
        foreach ($cmd as $arg) {
            $this->assertStringNotContainsString('pwd', $arg);
        }
    }

    public function testBuildDumpCmdCustomFormat(): void
    {
        $options = new DumpOptions(
            engine: 'postgres',
            host: 'localhost',
            port: 5432,
            user: 'postgres',
            database: 'db_test',
            customOptions: ['format' => 'custom', '--verbose']
        );

        $cmd = $this->engine->buildDumpCmd($options, '/tmp/test.dump');

        $this->assertContains('pg_dump', $cmd);
        $this->assertContains('--format=custom', $cmd);
        $this->assertContains('--verbose', $cmd);
    }

    public function testBuildRestoreCmdPlain(): void
    {
        $options = new RestoreOptions(
            engine: 'postgres',
            sourcePath: '/tmp/test.sql',
            host: '127.0.0.1',
            port: 5432,
            user: 'postgres',
            password: 'pwd',
            database: 'db_test'
        );

        // Not custom format
        $cmd = $this->engine->buildRestoreCmd($options, false);

        $this->assertContains('psql', $cmd);
        $this->assertContains('--host=127.0.0.1', $cmd);
        $this->assertContains('--port=5432', $cmd);
        $this->assertContains('--username=postgres', $cmd);
        $this->assertContains('--no-password', $cmd);
        $this->assertContains('--dbname=db_test', $cmd);

        // Source path is not in command argv for psql because psql uses stdin
        $this->assertNotContains('/tmp/test.sql', $cmd);
    }

    public function testBuildRestoreCmdCustom(): void
    {
        $options = new RestoreOptions(
            engine: 'postgres',
            sourcePath: '/tmp/test.dump',
            host: '127.0.0.1',
            port: 5432,
            user: 'postgres',
            password: 'pwd',
            database: 'db_test',
            customOptions: ['--clean']
        );

        $cmd = $this->engine->buildRestoreCmd($options, true);

        $this->assertContains('pg_restore', $cmd);
        $this->assertContains('--host=127.0.0.1', $cmd);
        $this->assertContains('--port=5432', $cmd);
        $this->assertContains('--username=postgres', $cmd);
        $this->assertContains('--no-password', $cmd);
        $this->assertContains('--dbname=db_test', $cmd);
        $this->assertContains('--clean', $cmd);
        $this->assertContains('/tmp/test.dump', $cmd);
    }
}
