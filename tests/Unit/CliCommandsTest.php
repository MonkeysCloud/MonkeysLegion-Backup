<?php

declare(strict_types=1);

namespace MonkeysLegion\Backup\Tests\Unit;

use PHPUnit\Framework\TestCase;
use MonkeysLegion\DI\Container;
use MonkeysLegion\Backup\Cli\Command\BaseCommand;
use MonkeysLegion\Backup\Cli\Command\EnginesCommand;
use MonkeysLegion\Backup\Cli\Command\ListCommand;
use MonkeysLegion\Backup\Cli\Command\DumpCommand;
use MonkeysLegion\Backup\Cli\Command\RestoreCommand;
use MonkeysLegion\Backup\Contract\StorageAdapterInterface;
use MonkeysLegion\Backup\Contract\CompressorInterface;
use MonkeysLegion\Backup\Contract\EngineInterface;
use MonkeysLegion\Backup\Engine\EngineRegistry;
use MonkeysLegion\Backup\ValueObject\BackupArtifact;
use MonkeysLegion\Backup\ValueObject\BackupMetadata;

final class CliCommandsTest extends TestCase
{
    private Container $container;

    protected function setUp(): void
    {
        parent::setUp();
        $this->container = new Container();
        Container::setInstance($this->container);
        BaseCommand::$capturedOutput = [];
    }

    protected function tearDown(): void
    {
        BaseCommand::$capturedOutput = null;
        Container::resetInstance();
        parent::tearDown();
    }

    public function testEnginesCommandListsRegisteredEngines(): void
    {
        $registry = EngineRegistry::default();
        $this->container->set(EngineRegistry::class, $registry);

        $command = new EnginesCommand();

        global $argv;
        $oldArgv = $argv;
        $argv = ['monkeys-backup', 'engines'];

        $code = $command();
        $output = implode("\n", BaseCommand::$capturedOutput ?? []);

        $argv = $oldArgv;

        $this->assertSame(0, $code);
        $this->assertStringContainsString('mysql', $output);
        $this->assertStringContainsString('postgres', $output);
        $this->assertStringContainsString('redis', $output);
    }

    public function testListCommandRendersBackupsTable(): void
    {
        $storage = $this->createMock(StorageAdapterInterface::class);
        $storage->method('list')->willReturn([
            ['key' => 'backups/db_2026.sql', 'size' => 1024, 'modified_at' => '2026-07-01 12:00:00'],
            ['key' => 'backups/db_2026.sql.meta', 'size' => 120, 'modified_at' => '2026-07-01 12:00:01'],
        ]);

        $this->container->set(StorageAdapterInterface::class, $storage);

        $command = new ListCommand();

        global $argv;
        $oldArgv = $argv;
        $argv = ['monkeys-backup', 'list', '--prefix=backups/'];

        $code = $command();
        $output = implode("\n", BaseCommand::$capturedOutput ?? []);

        $argv = $oldArgv;

        $this->assertSame(0, $code);
        $this->assertStringContainsString('backups/db_2026.sql', $output);
        $this->assertStringNotContainsString('db_2026.sql.meta', $output);
    }

    public function testDumpCommandExecutesBackup(): void
    {
        $engine = $this->createMock(EngineInterface::class);
        $engine->method('name')->willReturn('mysql');
        $engine->method('supports')->willReturn(false);
        
        $tempFile = tempnam(sys_get_temp_dir(), 'test_dump');
        file_put_contents($tempFile, 'dump data');
        $engine->method('dump')->willReturn(new BackupArtifact($tempFile, 'mysql', 'mydb'));

        $registry = new EngineRegistry();
        $registry->register('mysql', $engine);
        $this->container->set(EngineRegistry::class, $registry);

        $storage = $this->createMock(StorageAdapterInterface::class);
        $storage->expects($this->once())->method('upload')->willReturn('backups/mydb.sql');
        $this->container->set(StorageAdapterInterface::class, $storage);

        $compressor = $this->createMock(CompressorInterface::class);
        $this->container->set(CompressorInterface::class, $compressor);

        $command = new DumpCommand();

        global $argv;
        $oldArgv = $argv;
        $argv = ['monkeys-backup', 'dump', '--engine=mysql', '--database=mydb', '--key=backups/mydb.sql'];

        $code = $command();
        $output = implode("\n", BaseCommand::$capturedOutput ?? []);

        $argv = $oldArgv;

        $this->assertSame(0, $code);
        $this->assertStringContainsString('mydb', $output);
        $this->assertStringContainsString('backups/mydb.sql', $output);
    }

    public function testDumpCommandDryRunMode(): void
    {
        $registry = EngineRegistry::default();
        $this->container->set(EngineRegistry::class, $registry);

        $storage = $this->createMock(StorageAdapterInterface::class);
        $this->container->set(StorageAdapterInterface::class, $storage);

        $command = new DumpCommand();

        global $argv;
        $oldArgv = $argv;
        $argv = ['monkeys-backup', 'dump', '--engine=mysql', '--database=mydb', '--dry-run'];

        $code = $command();
        $output = implode("\n", BaseCommand::$capturedOutput ?? []);

        $argv = $oldArgv;

        $this->assertSame(0, $code);
        $this->assertStringContainsString('Dry-run mode active', $output);
    }

    public function testRestoreCommandExecutesRestore(): void
    {
        $engine = $this->createMock(EngineInterface::class);
        $engine->method('name')->willReturn('mysql');
        $engine->expects($this->once())->method('restore');

        $registry = new EngineRegistry();
        $registry->register('mysql', $engine);
        $this->container->set(EngineRegistry::class, $registry);

        $storage = $this->createMock(StorageAdapterInterface::class);
        $storage->method('download')->willReturnCallback(function (string $key, string $localPath) {
            if (str_ends_with($key, '.meta')) {
                $checksum = hash('sha256', 'backup data');
                $metadata = new BackupMetadata('mysql', '1.0.0', new \DateTimeImmutable(), $checksum, false, 11, 11);
                file_put_contents($localPath, $metadata->toJson());
            } else {
                file_put_contents($localPath, 'backup data');
            }
        });
        $this->container->set(StorageAdapterInterface::class, $storage);

        $compressor = $this->createMock(CompressorInterface::class);
        $this->container->set(CompressorInterface::class, $compressor);

        $command = new RestoreCommand();

        global $argv;
        $oldArgv = $argv;
        $argv = ['monkeys-backup', 'restore', '--engine=mysql', '--database=mydb', '--key=backups/mydb.sql'];

        $code = $command();
        $output = implode("\n", BaseCommand::$capturedOutput ?? []);

        $argv = $oldArgv;

        $this->assertSame(0, $code);
        $this->assertStringContainsString('mydb', $output);
        $this->assertStringContainsString('Running restore...', $output);
    }
}
