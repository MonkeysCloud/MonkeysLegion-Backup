<?php

declare(strict_types=1);

namespace MonkeysLegion\Backup\Tests\Unit;

use MonkeysLegion\Backup\Contract\CompressorInterface;
use MonkeysLegion\Backup\Contract\EngineInterface;
use MonkeysLegion\Backup\Contract\StorageAdapterInterface;
use MonkeysLegion\Backup\Engine\EngineRegistry;
use MonkeysLegion\Backup\Exception\BackupException;
use MonkeysLegion\Backup\Runner\RestoreRunner;
use MonkeysLegion\Backup\ValueObject\BackupMetadata;
use MonkeysLegion\Backup\ValueObject\RestoreOptions;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use DateTimeImmutable;

#[AllowMockObjectsWithoutExpectations]
final class RestoreRunnerTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = \sys_get_temp_dir() . '/mb_restore_runner_test_' . \uniqid();
        if (!\is_dir($this->tempDir)) {
            \mkdir($this->tempDir, 0755, true);
        }
    }

    protected function tearDown(): void
    {
        if (\is_dir($this->tempDir)) {
            $files = \glob("{$this->tempDir}/*");
            if ($files !== false) {
                foreach ($files as $file) {
                    if (\is_file($file)) {
                        \unlink($file);
                    }
                }
            }
            \rmdir($this->tempDir);
        }
    }

    public function testRestoreSuccessfulWithoutCompression(): void
    {
        $engine = $this->createStub(EngineInterface::class);
        $engine->method('name')->willReturn('mysql');

        // We capture the options passed to restore
        $restoredOptions = null;
        $engine->method('restore')->willReturnCallback(function (RestoreOptions $opts) use (&$restoredOptions) {
            $restoredOptions = $opts;
        });

        $registry = new EngineRegistry();
        $registry->register('mysql', $engine);

        $checksum = \hash('sha256', 'plain backup content');
        $metadata = new BackupMetadata(
            engine: 'mysql',
            version: '1.0.0',
            createdAt: new DateTimeImmutable(),
            checksum: $checksum,
            compressed: false,
            originalSize: \strlen('plain backup content'),
            compressedSize: \strlen('plain backup content')
        );

        $storage = new class($metadata->toJson(), 'plain backup content') implements StorageAdapterInterface {
            public function __construct(
                private string $metaJson,
                private string $backupContent
            ) {}

            public function download(string $remoteKey, string $localPath): void
            {
                if (\str_ends_with($remoteKey, '.meta')) {
                    \file_put_contents($localPath, $this->metaJson);
                } else {
                    \file_put_contents($localPath, $this->backupContent);
                }
            }
            public function upload(string $localPath, string $remoteKey, array $metadata = []): string
            {
                return '';
            }
            public function delete(string $remoteKey): void {}
            public function list(string $prefix = ''): array
            {
                return [];
            }
        };

        $compressor = $this->createStub(CompressorInterface::class);
        $runner = new RestoreRunner($registry, $storage, $compressor);

        $options = new RestoreOptions(
            engine: 'mysql',
            sourcePath: 'backups/mydb.sql',
            database: 'mydb'
        );

        $runner->run($options);

        $this->assertNotNull($restoredOptions);
        /** @var RestoreOptions $restoredOptions */
        $this->assertSame('mydb', $restoredOptions->database);
        // Verify source path was set to a local file, and it is cleaned up afterwards
        $this->assertFileDoesNotExist($restoredOptions->sourcePath);
    }

    public function testRestoreChecksumMismatchThrowsException(): void
    {
        $engine = $this->createStub(EngineInterface::class);
        $registry = new EngineRegistry();
        $registry->register('mysql', $engine);

        $metadata = new BackupMetadata(
            engine: 'mysql',
            version: '1.0.0',
            createdAt: new DateTimeImmutable(),
            checksum: 'mismatched_checksum',
            compressed: false,
            originalSize: 10,
            compressedSize: 10
        );

        $storage = new class($metadata->toJson(), 'some random content') implements StorageAdapterInterface {
            public function __construct(
                private string $metaJson,
                private string $backupContent
            ) {}

            public function download(string $remoteKey, string $localPath): void
            {
                if (\str_ends_with($remoteKey, '.meta')) {
                    \file_put_contents($localPath, $this->metaJson);
                } else {
                    \file_put_contents($localPath, $this->backupContent);
                }
            }
            public function upload(string $localPath, string $remoteKey, array $metadata = []): string
            {
                return '';
            }
            public function delete(string $remoteKey): void {}
            public function list(string $prefix = ''): array
            {
                return [];
            }
        };

        $compressor = $this->createStub(CompressorInterface::class);
        $runner = new RestoreRunner($registry, $storage, $compressor);

        $options = new RestoreOptions(
            engine: 'mysql',
            sourcePath: 'backups/mydb.sql',
            database: 'mydb'
        );

        $this->expectException(BackupException::class);
        $this->expectExceptionMessageIsOrContains('Checksum verification failed');

        $runner->run($options);
    }

    public function testRestoreWithDecompression(): void
    {
        $engine = $this->createStub(EngineInterface::class);
        $engine->method('name')->willReturn('mysql');

        $restoredOptions = null;
        $engine->method('restore')->willReturnCallback(function (RestoreOptions $opts) use (&$restoredOptions) {
            $restoredOptions = $opts;
        });

        $registry = new EngineRegistry();
        $registry->register('mysql', $engine);

        $checksum = \hash('sha256', 'compressed content');
        $metadata = new BackupMetadata(
            engine: 'mysql',
            version: '1.0.0',
            createdAt: new DateTimeImmutable(),
            checksum: $checksum,
            compressed: true,
            originalSize: 100,
            compressedSize: 50
        );

        $storage = new class($metadata->toJson(), 'compressed content') implements StorageAdapterInterface {
            public function __construct(
                private string $metaJson,
                private string $backupContent
            ) {}

            public function download(string $remoteKey, string $localPath): void
            {
                if (\str_ends_with($remoteKey, '.meta')) {
                    \file_put_contents($localPath, $this->metaJson);
                } else {
                    \file_put_contents($localPath, $this->backupContent);
                }
            }
            public function upload(string $localPath, string $remoteKey, array $metadata = []): string
            {
                return '';
            }
            public function delete(string $remoteKey): void {}
            public function list(string $prefix = ''): array
            {
                return [];
            }
        };

        $compressor = new class implements CompressorInterface {
            public ?string $decompressSource = null;
            public ?string $decompressTarget = null;

            public function compress(string $sourcePath, string $targetPath): void {}
            public function decompress(string $sourcePath, string $targetPath): void
            {
                $this->decompressSource = $sourcePath;
                $this->decompressTarget = $targetPath;
                \file_put_contents($targetPath, 'decompressed content');
            }
            public function extension(): string
            {
                return 'gz';
            }
        };

        $runner = new RestoreRunner($registry, $storage, $compressor);

        $options = new RestoreOptions(
            engine: 'mysql',
            sourcePath: 'backups/mydb.sql.gz',
            database: 'mydb'
        );

        $runner->run($options);

        $this->assertNotNull($restoredOptions);
        /** @var RestoreOptions $restoredOptions */
        $this->assertSame('mydb', $restoredOptions->database);
        // Verify source path was cleaned up
        $this->assertFileDoesNotExist($restoredOptions->sourcePath);
    }

    public function testRestoreMetaDownloadFailureThrows(): void
    {
        $engine = $this->createStub(EngineInterface::class);
        $registry = new EngineRegistry();
        $registry->register('mysql', $engine);

        $storage = $this->createMock(StorageAdapterInterface::class);
        $storage->method('download')
            ->willThrowException(new \RuntimeException('Storage unavailable'));

        $compressor = $this->createStub(CompressorInterface::class);
        $runner = new RestoreRunner($registry, $storage, $compressor);

        $this->expectException(BackupException::class);
        $this->expectExceptionMessageIsOrContains('Failed to download metadata');

        $runner->run(new RestoreOptions(
            engine: 'mysql',
            sourcePath: 'backups/mydb.sql',
            database: 'mydb'
        ));
    }

    public function testRestoreEngineFailureTriggersCleanup(): void
    {
        $engine = $this->createStub(EngineInterface::class);
        $engine->method('name')->willReturn('mysql');
        $engine->method('restore')->willThrowException(new \RuntimeException('Engine failed'));

        $registry = new EngineRegistry();
        $registry->register('mysql', $engine);

        $checksum = \hash('sha256', 'content');
        $metadata = new BackupMetadata(
            engine: 'mysql',
            version: '1.0.0',
            createdAt: new DateTimeImmutable(),
            checksum: $checksum,
            compressed: false,
            originalSize: 7,
            compressedSize: 7
        );

        $storage = new class($metadata->toJson(), 'content') implements StorageAdapterInterface {
            public function __construct(private string $metaJson, private string $backupContent) {}
            public function download(string $remoteKey, string $localPath): void
            {
                \file_put_contents($localPath, \str_ends_with($remoteKey, '.meta') ? $this->metaJson : $this->backupContent);
            }
            public function upload(string $localPath, string $remoteKey, array $metadata = []): string { return ''; }
            public function delete(string $remoteKey): void {}
            public function list(string $prefix = ''): array { return []; }
        };

        $compressor = $this->createStub(CompressorInterface::class);
        $runner = new RestoreRunner($registry, $storage, $compressor);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageIsOrContains('Engine failed');

        $runner->run(new RestoreOptions(
            engine: 'mysql',
            sourcePath: 'backups/mydb.sql',
            database: 'mydb'
        ));
    }

    public function testRestoreRunnerWithLogger(): void
    {
        $engine = $this->createStub(EngineInterface::class);
        $engine->method('name')->willReturn('mysql');

        $registry = new EngineRegistry();
        $registry->register('mysql', $engine);

        $checksum = \hash('sha256', 'data');
        $metadata = new BackupMetadata(
            engine: 'mysql',
            version: '1.0.0',
            createdAt: new DateTimeImmutable(),
            checksum: $checksum,
            compressed: false,
            originalSize: 4,
            compressedSize: 4
        );

        $storage = new class($metadata->toJson(), 'data') implements StorageAdapterInterface {
            public function __construct(private string $metaJson, private string $backupContent) {}
            public function download(string $remoteKey, string $localPath): void
            {
                \file_put_contents($localPath, \str_ends_with($remoteKey, '.meta') ? $this->metaJson : $this->backupContent);
            }
            public function upload(string $localPath, string $remoteKey, array $metadata = []): string { return ''; }
            public function delete(string $remoteKey): void {}
            public function list(string $prefix = ''): array { return []; }
        };

        $logMessages = [];
        $logger = new class($logMessages) implements \MonkeysLegion\Backup\Contract\LoggerInterface {
            public function __construct(private array &$messages) {}
            public function log(string $message, array $context = []): void
            {
                $this->messages[] = $message;
            }
        };

        $compressor = $this->createStub(CompressorInterface::class);
        $runner = new RestoreRunner($registry, $storage, $compressor, $logger);

        $runner->run(new RestoreOptions(
            engine: 'mysql',
            sourcePath: 'backups/mydb.sql',
            database: 'mydb',
            password: 'super_secret'
        ));

        $this->assertNotEmpty($logMessages);
        $this->assertStringContainsString('Starting restore', $logMessages[0]);
    }
}
