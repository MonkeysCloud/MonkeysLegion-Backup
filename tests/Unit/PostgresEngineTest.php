<?php

declare(strict_types=1);

namespace MonkeysLegion\Backup\Tests\Unit;

use MonkeysLegion\Backup\Engine\PostgresEngine;
use MonkeysLegion\Backup\Exception\EngineException;
use MonkeysLegion\Backup\Process\ProcessRunner;
use MonkeysLegion\Backup\ValueObject\DumpOptions;
use MonkeysLegion\Backup\ValueObject\RestoreOptions;
use PHPUnit\Framework\TestCase;

final class PostgresEngineTest extends TestCase
{
    private PostgresEngine $engine;
    private string $dummyDir;
    private string $oldPath;

    protected function setUp(): void
    {
        $this->engine = new PostgresEngine();
        $this->dummyDir = \sys_get_temp_dir() . '/mb_pg_dummies_' . \uniqid();
        \mkdir($this->dummyDir, 0755, true);
        \touch($this->dummyDir . '/pg_dump');
        \chmod($this->dummyDir . '/pg_dump', 0755);
        \touch($this->dummyDir . '/pg_restore');
        \chmod($this->dummyDir . '/pg_restore', 0755);
        \touch($this->dummyDir . '/psql');
        \chmod($this->dummyDir . '/psql', 0755);
        $this->oldPath = \getenv('PATH') ?: '';
        \putenv("PATH={$this->dummyDir}:" . $this->oldPath);
    }

    protected function tearDown(): void
    {
        \putenv("PATH={$this->oldPath}");
        @\unlink($this->dummyDir . '/pg_dump');
        @\unlink($this->dummyDir . '/pg_restore');
        @\unlink($this->dummyDir . '/psql');
        @\rmdir($this->dummyDir);
    }

    public function testName(): void
    {
        $this->assertSame('postgres', $this->engine->name());
    }

    public function testSupportsKnownFeatures(): void
    {
        $this->assertTrue($this->engine->supports('format-custom'));
        $this->assertTrue($this->engine->supports('format-plain'));
        $this->assertTrue($this->engine->supports('compression'));
        $this->assertFalse($this->engine->supports('unknown'));
    }

    // -------------------------------------------------------------------------
    // buildDumpCmd
    // -------------------------------------------------------------------------

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

    public function testBuildDumpCmdCustomFormatViaFcFlag(): void
    {
        $options = new DumpOptions(
            engine: 'postgres',
            database: 'db_test',
            customOptions: ['-Fc']
        );

        $cmd = $this->engine->buildDumpCmd($options, '/tmp/test.dump');
        $this->assertContains('--format=custom', $cmd);
        // The -Fc flag itself should be swallowed (not duplicated)
        $this->assertNotContains('-Fc', $cmd);
    }

    public function testBuildDumpCmdOmitsHostPortUserWhenNull(): void
    {
        $options = new DumpOptions(
            engine: 'postgres',
            database: 'localdb',
        );

        $cmd = $this->engine->buildDumpCmd($options, '/tmp/out.sql');

        foreach ($cmd as $arg) {
            $this->assertStringNotContainsString('--host=', $arg);
            $this->assertStringNotContainsString('--port=', $arg);
            $this->assertStringNotContainsString('--username=', $arg);
        }
    }

    // -------------------------------------------------------------------------
    // buildRestoreCmd
    // -------------------------------------------------------------------------

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

        $cmd = $this->engine->buildRestoreCmd($options, false);

        $this->assertContains('psql', $cmd);
        $this->assertContains('--host=127.0.0.1', $cmd);
        $this->assertContains('--port=5432', $cmd);
        $this->assertContains('--username=postgres', $cmd);
        $this->assertContains('--no-password', $cmd);
        $this->assertContains('--dbname=db_test', $cmd);
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

    public function testBuildRestoreCmdNullIsCustomAutoDetect(): void
    {
        // Source file doesn't exist → isCustomFormat returns false → uses psql
        $options = new RestoreOptions(
            engine: 'postgres',
            sourcePath: '/nonexistent.sql',
            database: 'db',
        );

        $cmd = $this->engine->buildRestoreCmd($options, null);
        $this->assertContains('psql', $cmd);
    }

    public function testBuildRestoreCmdOmitsHostPortUserWhenNull(): void
    {
        $options = new RestoreOptions(
            engine: 'postgres',
            sourcePath: '/tmp/test.sql',
            database: 'localdb',
        );

        $cmd = $this->engine->buildRestoreCmd($options, false);

        foreach ($cmd as $arg) {
            $this->assertStringNotContainsString('--host=', $arg);
            $this->assertStringNotContainsString('--port=', $arg);
            $this->assertStringNotContainsString('--username=', $arg);
        }
    }

    // -------------------------------------------------------------------------
    // restore() — error paths
    // -------------------------------------------------------------------------

    public function testRestoreThrowsWhenSourceNotReadable(): void
    {
        $this->expectException(EngineException::class);
        $this->expectExceptionMessageIsOrContains('not readable');

        $this->engine->restore(new RestoreOptions(
            engine: 'postgres',
            sourcePath: '/nonexistent/file.sql',
            database: 'mydb',
        ));
    }

    // -------------------------------------------------------------------------
    // dump() — error paths
    // -------------------------------------------------------------------------

    public function testDumpThrowsWhenDatabaseIsEmpty(): void
    {
        $this->expectException(EngineException::class);
        $this->expectExceptionMessageIsOrContains('database name');

        $this->engine->dump(new DumpOptions(engine: 'postgres', database: ''));
    }

    public function testDumpSuccess(): void
    {
        $runner = $this->createMock(ProcessRunner::class);
        $runner->expects($this->once())
            ->method('run')
            ->willReturnCallback(function (array $cmd) {
                // pg_dump writes to --file=<path>; simulate it
                foreach ($cmd as $arg) {
                    if (str_starts_with($arg, '--file=')) {
                        touch(substr($arg, \strlen('--file=')));
                    }
                }
                return '';
            });

        $engine = new PostgresEngine($runner);

        $options = new DumpOptions(
            engine: 'postgres',
            database: 'mydb',
            password: 'pass'
        );

        $artifact = $engine->dump($options);
        $this->assertSame('postgres', $artifact->engine);
        $this->assertSame('mydb', $artifact->database);
        $this->assertFileExists($artifact->localPath);
        \unlink($artifact->localPath);
    }

    public function testRestoreSuccessPlain(): void
    {
        $runner = $this->createMock(ProcessRunner::class);
        $runner->expects($this->once())
            ->method('run')
            ->willReturn('success');

        $engine = new PostgresEngine($runner);

        $tmpFile = tempnam(sys_get_temp_dir(), 'restore_test');
        file_put_contents($tmpFile, 'SELECT 1;');

        $options = new RestoreOptions(
            engine: 'postgres',
            sourcePath: $tmpFile,
            database: 'mydb',
            password: 'pass'
        );

        $engine->restore($options);
        $this->assertTrue(true);
        \unlink($tmpFile);
    }

    public function testRestoreSuccessCustom(): void
    {
        $runner = $this->createMock(ProcessRunner::class);
        $runner->expects($this->once())
            ->method('run')
            ->willReturn('success');

        $engine = new PostgresEngine($runner);

        $tmpFile = tempnam(sys_get_temp_dir(), 'restore_test');
        // Let's write the custom format header PGDMP
        file_put_contents($tmpFile, 'PGDMPxxxxxx');

        $options = new RestoreOptions(
            engine: 'postgres',
            sourcePath: $tmpFile,
            database: 'mydb',
            password: 'pass'
        );

        $engine->restore($options);
        $this->assertTrue(true);
        \unlink($tmpFile);
    }
}
