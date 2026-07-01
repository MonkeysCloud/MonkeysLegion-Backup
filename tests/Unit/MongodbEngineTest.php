<?php

declare(strict_types=1);

namespace MonkeysLegion\Backup\Tests\Unit;

use MonkeysLegion\Backup\Engine\MongodbEngine;
use MonkeysLegion\Backup\Exception\EngineException;
use MonkeysLegion\Backup\Process\ProcessRunner;
use MonkeysLegion\Backup\ValueObject\DumpOptions;
use MonkeysLegion\Backup\ValueObject\RestoreOptions;
use PHPUnit\Framework\TestCase;

final class MongodbEngineTest extends TestCase
{
    private MongodbEngine $engine;
    private string $dummyDir;
    private string $oldPath;

    protected function setUp(): void
    {
        $this->engine = new MongodbEngine();
        $this->dummyDir = \sys_get_temp_dir() . '/mb_mongo_dummies_' . \uniqid();
        \mkdir($this->dummyDir, 0755, true);
        \touch($this->dummyDir . '/mongodump');
        \chmod($this->dummyDir . '/mongodump', 0755);
        \touch($this->dummyDir . '/mongorestore');
        \chmod($this->dummyDir . '/mongorestore', 0755);
        $this->oldPath = \getenv('PATH') ?: '';
        \putenv("PATH={$this->dummyDir}:" . $this->oldPath);
    }

    protected function tearDown(): void
    {
        \putenv("PATH={$this->oldPath}");
        @\unlink($this->dummyDir . '/mongodump');
        @\unlink($this->dummyDir . '/mongorestore');
        @\rmdir($this->dummyDir);
    }

    public function testName(): void
    {
        $this->assertSame('mongodb', $this->engine->name());
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

    public function testBuildDumpCmdOmitsOptionalFieldsWhenNull(): void
    {
        $options = new DumpOptions(
            engine: 'mongodb',
            database: 'testdb',
        );

        $cmd = $this->engine->buildDumpCmd($options, '/tmp/test.archive');

        foreach ($cmd as $arg) {
            $this->assertStringNotContainsString('--host=', $arg);
            $this->assertStringNotContainsString('--port=', $arg);
            $this->assertStringNotContainsString('--username=', $arg);
            $this->assertStringNotContainsString('--password=', $arg);
        }
    }

    public function testBuildDumpCmdWithCustomOptions(): void
    {
        $options = new DumpOptions(
            engine: 'mongodb',
            database: 'testdb',
            customOptions: ['--gzip'],
        );

        $cmd = $this->engine->buildDumpCmd($options, '/tmp/test.archive');
        $this->assertContains('--gzip', $cmd);
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

    public function testBuildRestoreCmdOmitsOptionalFieldsWhenNull(): void
    {
        $options = new RestoreOptions(
            engine: 'mongodb',
            sourcePath: '/tmp/test.archive',
        );

        $cmd = $this->engine->buildRestoreCmd($options);

        foreach ($cmd as $arg) {
            $this->assertStringNotContainsString('--host=', $arg);
            $this->assertStringNotContainsString('--port=', $arg);
            $this->assertStringNotContainsString('--username=', $arg);
            $this->assertStringNotContainsString('--password=', $arg);
            $this->assertStringNotContainsString('--db=', $arg);
        }
    }

    public function testBuildRestoreCmdWithCustomOptions(): void
    {
        $options = new RestoreOptions(
            engine: 'mongodb',
            sourcePath: '/tmp/test.archive',
            database: 'db',
            customOptions: ['--drop'],
        );

        $cmd = $this->engine->buildRestoreCmd($options);
        $this->assertContains('--drop', $cmd);
    }

    // -------------------------------------------------------------------------
    // Error paths
    // -------------------------------------------------------------------------

    public function testDumpThrowsWhenDatabaseIsEmpty(): void
    {
        $this->expectException(EngineException::class);
        $this->expectExceptionMessageIsOrContains('database name');

        $this->engine->dump(new DumpOptions(engine: 'mongodb', database: ''));
    }

    public function testRestoreThrowsWhenSourceNotReadable(): void
    {
        $this->expectException(EngineException::class);
        $this->expectExceptionMessageIsOrContains('not readable');

        $this->engine->restore(new RestoreOptions(
            engine: 'mongodb',
            sourcePath: '/nonexistent/archive',
            database: 'db',
        ));
    }

    public function testDumpSuccess(): void
    {
        $runner = $this->createMock(ProcessRunner::class);
        $runner->expects($this->once())
            ->method('run')
            ->willReturnCallback(function (array $cmd) {
                // mongodump writes to --archive=<path>; simulate it
                foreach ($cmd as $arg) {
                    if (str_starts_with($arg, '--archive=')) {
                        touch(substr($arg, \strlen('--archive=')));
                    }
                }
                return '';
            });

        $engine = new MongodbEngine($runner);

        $options = new DumpOptions(
            engine: 'mongodb',
            database: 'mydb',
            password: 'pass'
        );

        $artifact = $engine->dump($options);
        $this->assertSame('mongodb', $artifact->engine);
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

        $engine = new MongodbEngine($runner);

        $tmpFile = tempnam(sys_get_temp_dir(), 'restore_test');
        file_put_contents($tmpFile, 'SELECT 1;');

        $options = new RestoreOptions(
            engine: 'mongodb',
            sourcePath: $tmpFile,
            database: 'mydb',
            password: 'pass'
        );

        $engine->restore($options);
        \unlink($tmpFile);
    }
}
