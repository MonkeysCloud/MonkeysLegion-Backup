<?php

declare(strict_types=1);

namespace MonkeysLegion\Backup\Tests\Unit;

use MonkeysLegion\Backup\Contract\CompressorInterface;
use MonkeysLegion\Backup\Contract\EngineInterface;
use MonkeysLegion\Backup\Contract\LoggerInterface;
use MonkeysLegion\Backup\Contract\StorageAdapterInterface;
use MonkeysLegion\Backup\Engine\EngineRegistry;
use MonkeysLegion\Backup\Exception\BackupException;
use MonkeysLegion\Backup\Runner\BackupRunner;
use MonkeysLegion\Backup\ValueObject\BackupArtifact;
use MonkeysLegion\Backup\ValueObject\DumpOptions;
use PHPUnit\Framework\TestCase;

final class BackupRunnerTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = \sys_get_temp_dir() . '/mb_backup_runner_test_' . \uniqid();
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

    public function testBackupSuccessfulWithoutCompression(): void
    {
        $dumpFile = "{$this->tempDir}/dump.sql";
        \file_put_contents($dumpFile, 'database dump content');

        $engine = $this->createStub(EngineInterface::class);
        $engine->method('name')->willReturn('mysql');
        $engine->method('dump')->willReturn(new BackupArtifact($dumpFile, 'mysql', 'mydb'));

        $registry = new EngineRegistry();
        $registry->register('mysql', $engine);

        $storage = new class implements StorageAdapterInterface {
            public ?string $uploadedPath = null;
            public ?string $remoteKey = null;
            /** @var array<string, mixed> */
            public array $metadata = [];

            public function upload(string $localPath, string $remoteKey, array $metadata = []): string
            {
                $this->uploadedPath = $localPath;
                $this->remoteKey = $remoteKey;
                $this->metadata = $metadata;
                return 'uri://test';
            }
            public function download(string $remoteKey, string $localPath): void {}
            public function delete(string $remoteKey): void {}
            public function list(string $prefix = ''): array { return []; }
        };

        $compressor = $this->createStub(CompressorInterface::class);

        $logger = new class implements LoggerInterface {
            /** @var list<string> */
            public array $logs = [];
            public function log(string $message, array $context = []): void
            {
                $this->logs[] = $message;
            }
        };

        $runner = new BackupRunner($registry, $storage, $compressor, $logger);

        $options = new DumpOptions(
            engine: 'mysql',
            database: 'mydb',
            compress: false
        );

        $result = $runner->run($options, 'backups/mydb.sql');

        $this->assertSame('backups/mydb.sql', $result->remoteKey);
        $this->assertSame(\hash('sha256', 'database dump content'), $result->checksum);
        $this->assertFileDoesNotExist($dumpFile); // runner must clean up local file
        $this->assertSame('backups/mydb.sql', $storage->remoteKey);
        $this->assertSame('mysql', $storage->metadata['engine'] ?? null);
        $this->assertFalse($storage->metadata['compressed'] ?? true);
        $this->assertStringContainsString('Backup process completed successfully in ', \implode(' ', $logger->logs));
    }

    public function testBackupSuccessfulWithCompression(): void
    {
        $dumpFile = "{$this->tempDir}/dump.sql";
        \file_put_contents($dumpFile, 'uncompressed content');

        $engine = $this->createStub(EngineInterface::class);
        $engine->method('name')->willReturn('mysql');
        $engine->method('supports')->willReturnCallback(fn($feature) => $feature === 'compression');
        $engine->method('dump')->willReturn(new BackupArtifact($dumpFile, 'mysql', 'mydb'));

        $registry = new EngineRegistry();
        $registry->register('mysql', $engine);

        $storage = new class implements StorageAdapterInterface {
            public ?string $uploadedPath = null;
            /** @var array<string, mixed> */
            public array $metadata = [];

            public function upload(string $localPath, string $remoteKey, array $metadata = []): string
            {
                $this->uploadedPath = $localPath;
                $this->metadata = $metadata;
                return 'uri://test';
            }
            public function download(string $remoteKey, string $localPath): void {}
            public function delete(string $remoteKey): void {}
            public function list(string $prefix = ''): array { return []; }
        };

        $compressor = new class implements CompressorInterface {
            public function compress(string $sourcePath, string $targetPath): void
            {
                \file_put_contents($targetPath, 'compressed content');
            }
            public function decompress(string $sourcePath, string $targetPath): void {}
            public function extension(): string { return 'gz'; }
        };

        $runner = new BackupRunner($registry, $storage, $compressor);

        $options = new DumpOptions(
            engine: 'mysql',
            database: 'mydb',
            compress: true
        );

        $result = $runner->run($options, 'backups/mydb.sql.gz');

        $this->assertSame(\hash('sha256', 'compressed content'), $result->checksum);
        $this->assertFileDoesNotExist($dumpFile);
        $this->assertFileDoesNotExist("{$dumpFile}.gz"); // cleaned up after upload
        $this->assertTrue($storage->metadata['compressed'] ?? false);
        $this->assertSame(\strlen('uncompressed content'), $storage->metadata['original_size'] ?? 0);
        $this->assertSame(\strlen('compressed content'), $storage->metadata['compressed_size'] ?? 0);
    }

    public function testCleansUpDumpOnUploadFailure(): void
    {
        $dumpFile = "{$this->tempDir}/dump.sql";
        \file_put_contents($dumpFile, 'content');

        $engine = $this->createStub(EngineInterface::class);
        $engine->method('name')->willReturn('mysql');
        $engine->method('dump')->willReturn(new BackupArtifact($dumpFile, 'mysql', 'mydb'));

        $registry = new EngineRegistry();
        $registry->register('mysql', $engine);

        $storage = $this->createStub(StorageAdapterInterface::class);
        $storage->method('upload')->willThrowException(new \RuntimeException('Upload failed'));

        $compressor = $this->createStub(CompressorInterface::class);

        $runner = new BackupRunner($registry, $storage, $compressor);

        $options = new DumpOptions(
            engine: 'mysql',
            database: 'mydb',
            compress: false
        );

        try {
            $runner->run($options, 'key');
            $this->fail('Expected exception');
        } catch (\RuntimeException $e) {
            $this->assertSame('Upload failed', $e->getMessage());
            $this->assertFileDoesNotExist($dumpFile); // verify clean up happened
        }
    }
}
