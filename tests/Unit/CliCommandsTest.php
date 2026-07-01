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

    public function testBaseCommandGetSafeOptionShortAndLong(): void
    {
        $command = new DumpCommand();

        global $argv;
        $oldArgv = $argv;

        $argv = ['monkeys-backup', 'dump', '--engine=mysql', '-d', 'mydb', '--port', '3306', '--compress', 'true', '-P', 'mypass'];

        $ref = new \ReflectionClass($command);
        $method = $ref->getMethod('getSafeOption');
        $method->setAccessible(true);

        $this->assertSame('mysql', $method->invoke($command, 'engine'));
        $this->assertSame('mydb', $method->invoke($command, 'database'));
        $this->assertSame('3306', $method->invoke($command, 'port'));
        $this->assertSame('true', $method->invoke($command, 'compress'));
        $this->assertSame('mypass', $method->invoke($command, 'password'));

        $argv = $oldArgv;
    }

    public function testBaseCommandGetDbOptionFromEnv(): void
    {
        $command = new DumpCommand();

        global $argv;
        $oldArgv = $argv;
        $argv = ['monkeys-backup', 'dump'];

        \putenv('MYSQL_DATABASE=mysql_env_db');
        \putenv('DB_PORT=9999');

        $ref = new \ReflectionClass($command);
        $getDbOption = $ref->getMethod('getDbOption');
        $getDbOption->setAccessible(true);

        $this->assertSame('mysql_env_db', $getDbOption->invoke($command, 'mysql', 'database'));
        $this->assertSame('9999', $getDbOption->invoke($command, 'mysql', 'port'));

        \putenv('MYSQL_DATABASE');
        \putenv('DB_PORT');

        $argv = $oldArgv;
    }

    public function testBaseCommandIsDryRun(): void
    {
        $command = new DumpCommand();

        global $argv;
        $oldArgv = $argv;

        $argv = ['monkeys-backup', 'dump', '--dry'];
        $ref = new \ReflectionClass($command);
        $isDryRun = $ref->getMethod('isDryRun');
        $isDryRun->setAccessible(true);

        $this->assertTrue($isDryRun->invoke($command));

        $argv = $oldArgv;
    }

    public function testBaseCommandResolveLogger(): void
    {
        $loggerMock = $this->createMock(\MonkeysLegion\Logger\LoggerInterface::class);
        $loggerMock->expects($this->once())->method('info')->with('test log message');
        $this->container->set(\MonkeysLegion\Logger\LoggerInterface::class, $loggerMock);

        $command = new DumpCommand();
        $ref = new \ReflectionClass($command);
        $resolveLogger = $ref->getMethod('resolveLogger');
        $resolveLogger->setAccessible(true);

        /** @var \MonkeysLegion\Backup\Contract\LoggerInterface $logger */
        $logger = $resolveLogger->invoke($command);
        $this->assertNotNull($logger);
        $logger->log('test log message');
    }

    public function testDumpCommandMissingEngineOption(): void
    {
        $command = new DumpCommand();

        global $argv;
        $oldArgv = $argv;
        $argv = ['monkeys-backup', 'dump'];

        $code = $command();
        $output = implode("\n", BaseCommand::$capturedOutput ?? []);

        $argv = $oldArgv;

        $this->assertSame(1, $code);
        $this->assertStringContainsString('Error: --engine option is required.', $output);
    }

    public function testDumpCommandMissingDatabaseOption(): void
    {
        $command = new DumpCommand();

        global $argv;
        $oldArgv = $argv;
        $argv = ['monkeys-backup', 'dump', '--engine=mysql'];

        $code = $command();
        $output = implode("\n", BaseCommand::$capturedOutput ?? []);

        $argv = $oldArgv;

        $this->assertSame(1, $code);
        $this->assertStringContainsString('Error: --database option (or environment variable) is required.', $output);
    }

    public function testDumpCommandHandlesServiceRegistrationException(): void
    {
        // No services registered in the container
        $command = new DumpCommand();

        global $argv;
        $oldArgv = $argv;
        $argv = ['monkeys-backup', 'dump', '--engine=mysql', '--database=mydb'];

        $code = $command();
        $output = implode("\n", BaseCommand::$capturedOutput ?? []);

        $argv = $oldArgv;

        $this->assertSame(1, $code);
        $this->assertStringContainsString('Required services are not registered', $output);
    }

    public function testRestoreCommandMissingEngineOption(): void
    {
        $command = new RestoreCommand();

        global $argv;
        $oldArgv = $argv;
        $argv = ['monkeys-backup', 'restore'];

        $code = $command();
        $output = implode("\n", BaseCommand::$capturedOutput ?? []);

        $argv = $oldArgv;

        $this->assertSame(1, $code);
        $this->assertStringContainsString('Error: --engine option is required.', $output);
    }

    public function testRestoreCommandMissingDatabaseOption(): void
    {
        $command = new RestoreCommand();

        global $argv;
        $oldArgv = $argv;
        $argv = ['monkeys-backup', 'restore', '--engine=mysql'];

        $code = $command();
        $output = implode("\n", BaseCommand::$capturedOutput ?? []);

        $argv = $oldArgv;

        $this->assertSame(1, $code);
        $this->assertStringContainsString('Error: --database option (or environment variable) is required.', $output);
    }

    public function testRestoreCommandMissingKeyOption(): void
    {
        $command = new RestoreCommand();

        global $argv;
        $oldArgv = $argv;
        $argv = ['monkeys-backup', 'restore', '--engine=mysql', '--database=mydb'];

        $code = $command();
        $output = implode("\n", BaseCommand::$capturedOutput ?? []);

        $argv = $oldArgv;

        $this->assertSame(1, $code);
        $this->assertStringContainsString('Error: --key option (backup key) is required.', $output);
    }

    public function testListCommandMissingStorageService(): void
    {
        $command = new ListCommand();

        global $argv;
        $oldArgv = $argv;
        $argv = ['monkeys-backup', 'list'];

        $code = $command();
        $output = implode("\n", BaseCommand::$capturedOutput ?? []);

        $argv = $oldArgv;

        $this->assertSame(1, $code);
        $this->assertStringContainsString('StorageAdapterInterface is not registered', $output);
    }

    public function testListCommandHandlesListFailure(): void
    {
        $storage = $this->createMock(StorageAdapterInterface::class);
        $storage->method('list')->willThrowException(new \RuntimeException('List storage failure'));
        $this->container->set(StorageAdapterInterface::class, $storage);

        $command = new ListCommand();

        global $argv;
        $oldArgv = $argv;
        $argv = ['monkeys-backup', 'list'];

        $code = $command();
        $output = implode("\n", BaseCommand::$capturedOutput ?? []);

        $argv = $oldArgv;

        $this->assertSame(1, $code);
        $this->assertStringContainsString('Failed to list backups: List storage failure', $output);
    }

    public function testListCommandNoBackupsFound(): void
    {
        $storage = $this->createMock(StorageAdapterInterface::class);
        $storage->method('list')->willReturn([]);
        $this->container->set(StorageAdapterInterface::class, $storage);

        $command = new ListCommand();

        global $argv;
        $oldArgv = $argv;
        $argv = ['monkeys-backup', 'list'];

        $code = $command();
        $output = implode("\n", BaseCommand::$capturedOutput ?? []);

        $argv = $oldArgv;

        $this->assertSame(0, $code);
        $this->assertStringContainsString('No backups found in storage.', $output);
    }

    // -------------------------------------------------------------------------
    // EnginesCommand — success with an explicitly registered engine
    // -------------------------------------------------------------------------

    public function testEnginesCommandSuccess(): void
    {
        $engine = $this->createMock(EngineInterface::class);
        $engine->method('supports')->willReturn(true);

        $registry = new EngineRegistry();
        $registry->register('mysql', $engine);
        $this->container->set(EngineRegistry::class, $registry);

        $command = new EnginesCommand();

        global $argv;
        $oldArgv = $argv;
        $argv = ['monkeys-backup', 'engines'];

        $code = $command();
        $output = implode("\n", BaseCommand::$capturedOutput ?? []);

        $argv = $oldArgv;

        $this->assertSame(0, $code);
        $this->assertStringContainsString('Registered backup engines', $output);
    }

    // -------------------------------------------------------------------------
    // DumpCommand dry-run path
    // -------------------------------------------------------------------------

    public function testDumpCommandDryRun(): void
    {
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

    // -------------------------------------------------------------------------
    // RestoreCommand dry-run + service exception paths
    // -------------------------------------------------------------------------

    public function testRestoreCommandDryRun(): void
    {
        $command = new RestoreCommand();

        global $argv;
        $oldArgv = $argv;
        $argv = ['monkeys-backup', 'restore', '--engine=mysql', '--database=mydb', '--key=backups/x.sql', '--dry-run'];

        $code = $command();
        $output = implode("\n", BaseCommand::$capturedOutput ?? []);

        $argv = $oldArgv;

        $this->assertSame(0, $code);
        $this->assertStringContainsString('Dry-run mode active', $output);
    }

    public function testRestoreCommandHandlesServiceRegistrationException(): void
    {
        $command = new RestoreCommand();

        global $argv;
        $oldArgv = $argv;
        $argv = ['monkeys-backup', 'restore', '--engine=mysql', '--database=mydb', '--key=backups/x.sql'];

        $code = $command();
        $output = implode("\n", BaseCommand::$capturedOutput ?? []);

        $argv = $oldArgv;

        $this->assertSame(1, $code);
        $this->assertStringContainsString('Required services are not registered', $output);
    }

    // -------------------------------------------------------------------------
    // BackupException::getContext()
    // -------------------------------------------------------------------------

    public function testBackupExceptionGetContext(): void
    {
        $e = new \MonkeysLegion\Backup\Exception\BackupException(
            'test error',
            0,
            ['key' => 'value']
        );

        $this->assertSame(['key' => 'value'], $e->getContext());
        $this->assertSame('test error', $e->getMessage());
    }

    public function testBaseCommandOutputFallbacks(): void
    {
        $oldCaptured = BaseCommand::$capturedOutput;
        BaseCommand::$capturedOutput = null;

        ob_start();
        try {
            $command = new class extends BaseCommand {
                protected function handle(): int { return 0; }
                public function callInfo(): void { $this->info('test info'); }
                public function callLine(): void { $this->line('test line'); }
                public function callWarn(): void { $this->warn('test warn'); }
                public function callError(): void { $this->error('test error'); }
                public function callTable(): void { $this->table(['H'], [['R']]); }
            };

            $command->callInfo();
            $command->callLine();
            $command->callWarn();
            $command->callError();
            $command->callTable();
            $command->logMessage('hello');
        } finally {
            ob_end_clean();
            BaseCommand::$capturedOutput = $oldCaptured;
        }
        $this->assertTrue(true);
    }

    public function testBaseCommandResolveLoggerException(): void
    {
        $mockContainer = $this->createMock(Container::class);
        $mockContainer->method('has')->willThrowException(new \RuntimeException('DI Container Error'));
        Container::setInstance($mockContainer);

        try {
            $command = new DumpCommand();
            $ref = new \ReflectionClass($command);
            $resolveLogger = $ref->getMethod('resolveLogger');
            $resolveLogger->setAccessible(true);

            $logger = $resolveLogger->invoke($command);
            $this->assertNull($logger);
        } finally {
            Container::setInstance($this->container);
        }
    }
}
