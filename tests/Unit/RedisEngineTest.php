<?php

declare(strict_types=1);

namespace MonkeysLegion\Backup\Tests\Unit;

use MonkeysLegion\Backup\Engine\RedisEngine;
use MonkeysLegion\Backup\Exception\EngineException;
use MonkeysLegion\Backup\Process\ProcessRunner;
use MonkeysLegion\Backup\ValueObject\DumpOptions;
use MonkeysLegion\Backup\ValueObject\RestoreOptions;
use PHPUnit\Framework\TestCase;

final class RedisEngineTest extends TestCase
{
    private RedisEngine $engine;
    private string $tempDir;
    private string $dummyDir;
    private string $oldPath;

    protected function setUp(): void
    {
        $this->engine  = new RedisEngine();
        $this->tempDir = \sys_get_temp_dir() . '/mb_redis_test_' . \uniqid();
        \mkdir($this->tempDir, 0755, true);

        $this->dummyDir = \sys_get_temp_dir() . '/mb_redis_dummies_' . \uniqid();
        \mkdir($this->dummyDir, 0755, true);
        \touch($this->dummyDir . '/redis-cli');
        \chmod($this->dummyDir . '/redis-cli', 0755);
        $this->oldPath = \getenv('PATH') ?: '';
        \putenv("PATH={$this->dummyDir}:" . $this->oldPath);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tempDir);
        \putenv("PATH={$this->oldPath}");
        @\unlink($this->dummyDir . '/redis-cli');
        @\rmdir($this->dummyDir);
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
        foreach ($cmd as $arg) {
            $this->assertStringNotContainsString('pwd', $arg);
        }
    }

    public function testBuildDumpCmdOmitsOptionalFieldsWhenNull(): void
    {
        $cmd = $this->engine->buildDumpCmd(
            new DumpOptions(engine: 'redis', database: 'default'),
            '/tmp/test.rdb'
        );
        $this->assertNotContains('-h', $cmd);
        $this->assertNotContains('-p', $cmd);
        $this->assertNotContains('--user', $cmd);
    }

    public function testBuildDumpCmdWithCustomOptions(): void
    {
        $cmd = $this->engine->buildDumpCmd(
            new DumpOptions(engine: 'redis', database: 'default', customOptions: ['--extra-flag']),
            '/tmp/test.rdb'
        );
        $this->assertContains('--extra-flag', $cmd);
    }

    public function testRestoreCopiesRdbToDestination(): void
    {
        $src  = "{$this->tempDir}/source.rdb";
        $dest = "{$this->tempDir}/dump.rdb";
        \file_put_contents($src, 'REDIS0011');

        $this->engine->restore(new RestoreOptions(engine: 'redis', sourcePath: $src, database: $dest));

        $this->assertFileExists($dest);
        $this->assertStringEqualsFile($dest, 'REDIS0011');
    }

    public function testRestoreCreatesDestinationDirectory(): void
    {
        $src  = "{$this->tempDir}/source.rdb";
        $dest = "{$this->tempDir}/nested/dir/dump.rdb";
        \file_put_contents($src, 'REDIS0011');

        $this->engine->restore(new RestoreOptions(engine: 'redis', sourcePath: $src, database: $dest));
        $this->assertFileExists($dest);
    }

    public function testRestoreThrowsWhenDatabaseIsEmpty(): void
    {
        $src = "{$this->tempDir}/source.rdb";
        \file_put_contents($src, 'REDIS');
        $this->expectException(EngineException::class);
        $this->engine->restore(new RestoreOptions(engine: 'redis', sourcePath: $src, database: ''));
    }

    public function testRestoreThrowsWhenSourceNotReadable(): void
    {
        $this->expectException(EngineException::class);
        $this->engine->restore(new RestoreOptions(
            engine: 'redis',
            sourcePath: '/nonexistent/dump.rdb',
            database: "{$this->tempDir}/dump.rdb",
        ));
    }

    public function testDumpSuccess(): void
    {
        $runner = $this->createMock(ProcessRunner::class);
        $runner->expects($this->once())
            ->method('run')
            ->willReturnCallback(function (array $cmd) {
                // redis-cli --rdb <path>; the path is the element after '--rdb'
                $prevWasRdb = false;
                foreach ($cmd as $arg) {
                    if ($prevWasRdb) {
                        touch($arg);
                        break;
                    }
                    $prevWasRdb = ($arg === '--rdb');
                }
                return '';
            });

        $engine = new RedisEngine($runner);

        $options = new DumpOptions(
            engine: 'redis',
            database: 'default',
            password: 'pass'
        );

        $artifact = $engine->dump($options);
        $this->assertSame('redis', $artifact->engine);
        $this->assertFileExists($artifact->localPath);
        \unlink($artifact->localPath);
    }

    private function removeDir(string $path): void
    {
        if (!\is_dir($path)) return;
        foreach (\array_diff(\scandir($path) ?: [], ['.', '..']) as $entry) {
            $full = "{$path}/{$entry}";
            \is_dir($full) ? $this->removeDir($full) : \unlink($full);
        }
        \rmdir($path);
    }
}
