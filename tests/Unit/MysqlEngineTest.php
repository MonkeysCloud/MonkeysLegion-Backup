<?php

declare(strict_types=1);

namespace MonkeysLegion\Backup\Tests\Unit;

use MonkeysLegion\Backup\Engine\MysqlEngine;
use MonkeysLegion\Backup\Exception\EngineException;
use MonkeysLegion\Backup\Process\ProcessRunner;
use MonkeysLegion\Backup\ValueObject\DumpOptions;
use MonkeysLegion\Backup\ValueObject\RestoreOptions;
use PHPUnit\Framework\TestCase;

final class MysqlEngineTest extends TestCase
{
    private MysqlEngine $engine;
    private string $dummyDir;
    private string $oldPath;

    protected function setUp(): void
    {
        $this->engine = new MysqlEngine();
        $this->dummyDir = \sys_get_temp_dir() . '/mb_mysql_dummies_' . \uniqid();
        \mkdir($this->dummyDir, 0755, true);
        \touch($this->dummyDir . '/mysqldump');
        \chmod($this->dummyDir . '/mysqldump', 0755);
        \touch($this->dummyDir . '/mysql');
        \chmod($this->dummyDir . '/mysql', 0755);
        $this->oldPath = \getenv('PATH') ?: '';
        \putenv("PATH={$this->dummyDir}:" . $this->oldPath);
    }

    protected function tearDown(): void
    {
        \putenv("PATH={$this->oldPath}");
        @\unlink($this->dummyDir . '/mysqldump');
        @\unlink($this->dummyDir . '/mysql');
        @\rmdir($this->dummyDir);
    }

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

    public function testRestoreCmdOmitsHostPortUserWhenNull(): void
    {
        $options = new RestoreOptions(
            engine: 'mysql',
            sourcePath: '/tmp/dump.sql',
            database: 'mydb',
        );

        $cmd = $this->engine->buildRestoreCmd($options);

        foreach ($cmd as $arg) {
            $this->assertStringNotContainsString('--host=', $arg);
            $this->assertStringNotContainsString('--port=', $arg);
            $this->assertStringNotContainsString('--user=', $arg);
        }
    }

    // -------------------------------------------------------------------------
    // dump() — error paths via mocked ProcessRunner
    // -------------------------------------------------------------------------

    public function testDumpThrowsWhenDatabaseIsEmpty(): void
    {
        $this->expectException(EngineException::class);
        $this->expectExceptionMessageIsOrContains('database name');

        $this->engine->dump(new DumpOptions(engine: 'mysql', database: ''));
    }

    public function testDumpThrowsWhenBinaryMissing(): void
    {
        $oldPath = \getenv('PATH') ?: '';
        \putenv('PATH=/empty');
        try {
            $this->expectException(EngineException::class);
            $this->expectExceptionMessageIsOrContains('Required binary "mysqldump" not found on PATH.');
            $this->engine->dump(new DumpOptions(engine: 'mysql', database: 'test', host: '127.0.0.1'));
        } finally {
            \putenv("PATH={$oldPath}");
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
            engine: 'mysql',
            sourcePath: '/nonexistent/file.sql',
            database: 'mydb',
        ));
    }

    public function testDumpSuccess(): void
    {
        $runner = $this->createMock(ProcessRunner::class);
        $runner->expects($this->once())
            ->method('run')
            ->willReturnCallback(function (array $cmd) {
                // mysqldump writes the output file itself; simulate it
                foreach ($cmd as $arg) {
                    if (str_starts_with($arg, '--result-file=')) {
                        touch(substr($arg, strlen('--result-file=')));
                    }
                }
                return '';
            });

        $engine = new MysqlEngine($runner);

        $options = new DumpOptions(
            engine: 'mysql',
            database: 'mydb',
            password: 'pass'
        );

        $artifact = $engine->dump($options);
        $this->assertSame('mysql', $artifact->engine);
        $this->assertSame('mydb', $artifact->database);
        $this->assertFileExists($artifact->localPath);
        \unlink($artifact->localPath);
    }

    public function testRestoreSuccess(): void
    {
        $runner = $this->createMock(ProcessRunner::class);
        $runner->expects($this->once())
            ->method('run')
            ->willReturn('success');

        $engine = new MysqlEngine($runner);

        $tmpFile = tempnam(sys_get_temp_dir(), 'restore_test');
        file_put_contents($tmpFile, 'SELECT 1;');

        $options = new RestoreOptions(
            engine: 'mysql',
            sourcePath: $tmpFile,
            database: 'mydb',
            password: 'pass'
        );

        $engine->restore($options);
        \unlink($tmpFile);
    }
}
