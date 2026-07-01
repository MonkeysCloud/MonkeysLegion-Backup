<?php

declare(strict_types=1);

namespace MonkeysLegion\Backup\Tests\Unit;

use DateTimeImmutable;
use InvalidArgumentException;
use MonkeysLegion\Backup\ValueObject\BackupArtifact;
use MonkeysLegion\Backup\ValueObject\BackupMetadata;
use MonkeysLegion\Backup\ValueObject\BackupResult;
use MonkeysLegion\Backup\ValueObject\DumpOptions;
use MonkeysLegion\Backup\ValueObject\RestoreOptions;
use MonkeysLegion\Backup\ValueObject\StorageConfig;
use PHPUnit\Framework\TestCase;

final class ValueObjectTest extends TestCase
{
    public function testDumpOptionsImmutability(): void
    {
        $options = new DumpOptions(
            engine: 'mysql',
            host: '127.0.0.1',
            port: 3306,
            user: 'root',
            password: 'pwd',
            database: 'db',
            compress: true,
            customOptions: ['--single-transaction']
        );

        $this->assertSame('mysql', $options->engine);
        $this->assertSame('127.0.0.1', $options->host);
        $this->assertSame(3306, $options->port);
        $this->assertSame('root', $options->user);
        $this->assertSame('pwd', $options->password);
        $this->assertSame('db', $options->database);
        $this->assertTrue($options->compress);
        $this->assertSame(['--single-transaction'], $options->customOptions);
    }

    public function testRestoreOptionsImmutability(): void
    {
        $options = new RestoreOptions(
            engine: 'postgres',
            sourcePath: '/tmp/backup.sql',
            host: 'localhost',
            port: 5432,
            user: 'postgres',
            password: 'secretpassword',
            database: 'production',
            customOptions: ['-Fc']
        );

        $this->assertSame('postgres', $options->engine);
        $this->assertSame('/tmp/backup.sql', $options->sourcePath);
        $this->assertSame('localhost', $options->host);
        $this->assertSame(5432, $options->port);
        $this->assertSame('postgres', $options->user);
        $this->assertSame('secretpassword', $options->password);
        $this->assertSame('production', $options->database);
        $this->assertSame(['-Fc'], $options->customOptions);
    }

    public function testBackupArtifactImmutability(): void
    {
        $now = new DateTimeImmutable();
        $artifact = new BackupArtifact(
            localPath: '/tmp/dump.sql',
            engine: 'mysql',
            database: 'app',
            createdAt: $now
        );

        $this->assertSame('/tmp/dump.sql', $artifact->localPath);
        $this->assertSame('mysql', $artifact->engine);
        $this->assertSame('app', $artifact->database);
        $this->assertSame($now, $artifact->createdAt);
    }

    public function testBackupResultImmutabilityAndGetters(): void
    {
        $result = new BackupResult(
            remoteKey: 'mysql/app_2026.sql',
            sizeBytes: 1024,
            checksum: 'sha256hash',
            duration: 1.5
        );

        $this->assertSame('mysql/app_2026.sql', $result->remoteKey());
        $this->assertSame(1024, $result->sizeBytes());
        $this->assertSame('sha256hash', $result->checksum());
        $this->assertSame(1.5, $result->duration());
    }

    public function testBackupMetadataSerialization(): void
    {
        $now = new DateTimeImmutable('2026-07-01T12:00:00Z');
        $metadata = new BackupMetadata(
            engine: 'postgres',
            version: '1.0.0',
            createdAt: $now,
            checksum: 'abc123hash',
            compressed: true,
            originalSize: 5000,
            compressedSize: 1200
        );

        $array = $metadata->toArray();
        $this->assertSame('postgres', $array['engine']);
        $this->assertSame('1.0.0', $array['version']);
        $this->assertSame($now->format(DateTimeImmutable::ATOM), $array['created_at']);
        $this->assertSame('abc123hash', $array['checksum']);
        $this->assertTrue($array['compressed']);
        $this->assertSame(5000, $array['original_size']);
        $this->assertSame(1200, $array['compressed_size']);

        $json = $metadata->toJson();
        $restored = BackupMetadata::fromJson($json);

        $this->assertSame($metadata->engine, $restored->engine);
        $this->assertSame($metadata->version, $restored->version);
        $this->assertSame($metadata->createdAt->getTimestamp(), $restored->createdAt->getTimestamp());
        $this->assertSame($metadata->checksum, $restored->checksum);
        $this->assertSame($metadata->compressed, $restored->compressed);
        $this->assertSame($metadata->originalSize, $restored->originalSize);
        $this->assertSame($metadata->compressedSize, $restored->compressedSize);
    }

    public function testStorageConfigFromArrayAndGetters(): void
    {
        $config = StorageConfig::fromArray([
            'driver' => 's3',
            'bucket' => 'my-bucket',
            'prefix' => 'backups/',
            'nested' => [
                'key' => 'val'
            ]
        ]);

        $this->assertSame('s3', $config->driver());
        $this->assertSame('my-bucket', $config->get('bucket'));
        $this->assertSame('backups/', $config->get('prefix'));
        $this->assertSame(['key' => 'val'], $config->get('nested'));
        $this->assertNull($config->get('non-existent'));
        $this->assertSame('default', $config->get('non-existent', 'default'));
        $this->assertArrayHasKey('driver', $config->all());
    }

    public function testStorageConfigValidation(): void
    {
        $this->expectException(InvalidArgumentException::class);
        StorageConfig::fromArray([
            'bucket' => 'my-bucket'
        ]);
    }

    public function testStorageConfigFromJsonFile(): void
    {
        $tempFile = \tempnam(\sys_get_temp_dir(), 'conf');
        $this->assertNotFalse($tempFile);

        try {
            \file_put_contents($tempFile, \json_encode([
                'driver' => 'gcs',
                'bucket' => 'gcs-bucket',
                'prefix' => 'prod/'
            ]));

            $config = StorageConfig::fromJsonFile($tempFile);
            $this->assertSame('gcs', $config->driver());
            $this->assertSame('gcs-bucket', $config->get('bucket'));
            $this->assertSame('prod/', $config->get('prefix'));
        } finally {
            \unlink($tempFile);
        }
    }

    public function testStorageConfigFromJsonFileThrowsOnInvalidFile(): void
    {
        $this->expectException(InvalidArgumentException::class);
        StorageConfig::fromJsonFile('/path/to/non-existent-file.json');
    }
}
