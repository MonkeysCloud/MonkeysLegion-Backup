<?php

declare(strict_types=1);

namespace MonkeysLegion\Backup\Tests\Unit;

use MonkeysLegion\Backup\Engine\MysqlEngine;
use MonkeysLegion\Backup\ValueObject\DumpOptions;
use MonkeysLegion\Backup\ValueObject\RestoreOptions;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for MysqlEngine.
 *
 * These tests only assert that the correct argv arrays are built.
 * No live database connection is required.
 */
final class MysqlEngineTest extends TestCase
{
    private MysqlEngine $engine;

    protected function setUp(): void
    {
        $this->engine = new MysqlEngine();
    }

    // -------------------------------------------------------------------------
    // name / supports
    // -------------------------------------------------------------------------

    public function testName(): void
    {
        $this->assertSame('mysql', $this->engine->name());
    }

    public function testSupportsKnownFeatures(): void
    {
        $this->assertTrue($this->engine->supports('single-transaction'));
        $this->assertTrue($this->engine->supports('routines'));
        $this->assertTrue($this->engine->supports('triggers'));
        $this->assertTrue($this->engine->supports('compression'));
    }

    public function testDoesNotSupportUnknownFeature(): void
    {
        $this->assertFalse($this->engine->supports('pitr'));
    }

    // -------------------------------------------------------------------------
    // buildDumpCmd
    // -------------------------------------------------------------------------

    public function testDumpCmdContainsMandatoryFlags(): void
    {
        $options = new DumpOptions(
            engine: 'mysql',
            host: '127.0.0.1',
            port: 3306,
            user: 'root',
            password: 'secret',
            database: 'mydb',
        );

        $cmd = $this->engine->buildDumpCmd($options, '/tmp/out.sql');

        $this->assertContains('mysqldump', $cmd);
        $this->assertContains('--single-transaction', $cmd);
        $this->assertContains('--routines', $cmd);
        $this->assertContains('--triggers', $cmd);
        $this->assertContains('--result-file=/tmp/out.sql', $cmd);
        $this->assertContains('mydb', $cmd);
    }

    public function testDumpCmdContainsHostPortUser(): void
    {
        $options = new DumpOptions(
            engine: 'mysql',
            host: 'db.example.com',
            port: 3307,
            user: 'admin',
            database: 'shop',
        );

        $cmd = $this->engine->buildDumpCmd($options, '/tmp/out.sql');

        $this->assertContains('--host=db.example.com', $cmd);
        $this->assertContains('--port=3307', $cmd);
        $this->assertContains('--user=admin', $cmd);
    }

    public function testDumpCmdPasswordNeverInArgv(): void
    {
        $options = new DumpOptions(
            engine: 'mysql',
            host: '127.0.0.1',
            user: 'root',
            password: 'super_secret',
            database: 'mydb',
        );

        $cmd = $this->engine->buildDumpCmd($options, '/tmp/out.sql');

        // Password must not appear anywhere in the argv array
        foreach ($cmd as $arg) {
            $this->assertStringNotContainsString('super_secret', $arg);
        }
    }

    public function testDumpCmdCustomOptionsAppended(): void
    {
        $options = new DumpOptions(
            engine: 'mysql',
            database: 'mydb',
            customOptions: ['--skip-lock-tables', '--hex-blob'],
        );

        $cmd = $this->engine->buildDumpCmd($options, '/tmp/out.sql');

        $this->assertContains('--skip-lock-tables', $cmd);
        $this->assertContains('--hex-blob', $cmd);
    }

    public function testDumpCmdOmitsHostPortUserWhenNull(): void
    {
        $options = new DumpOptions(
            engine: 'mysql',
            database: 'localdb',
        );

        $cmd = $this->engine->buildDumpCmd($options, '/tmp/out.sql');

        foreach ($cmd as $arg) {
            $this->assertStringNotContainsString('--host=', $arg);
            $this->assertStringNotContainsString('--port=', $arg);
            $this->assertStringNotContainsString('--user=', $arg);
        }
    }

    // -------------------------------------------------------------------------
    // buildRestoreCmd
    // -------------------------------------------------------------------------

    public function testRestoreCmdContainsHostPortUser(): void
    {
        $options = new RestoreOptions(
            engine: 'mysql',
            sourcePath: '/tmp/dump.sql',
            host: '127.0.0.1',
            port: 3306,
            user: 'root',
            password: 'secret',
            database: 'mydb',
        );

        $cmd = $this->engine->buildRestoreCmd($options);

        $this->assertContains('mysql', $cmd);
        $this->assertContains('--host=127.0.0.1', $cmd);
        $this->assertContains('--port=3306', $cmd);
        $this->assertContains('--user=root', $cmd);
        $this->assertContains('mydb', $cmd);
    }

    public function testRestoreCmdPasswordNeverInArgv(): void
    {
        $options = new RestoreOptions(
            engine: 'mysql',
            sourcePath: '/tmp/dump.sql',
            password: 'top_secret',
            database: 'mydb',
        );

        $cmd = $this->engine->buildRestoreCmd($options);

        foreach ($cmd as $arg) {
            $this->assertStringNotContainsString('top_secret', $arg);
        }
    }
}
