<?php

declare(strict_types=1);

namespace MonkeysLegion\Backup\Tests\Unit;

use MonkeysLegion\Backup\Contract\StorageAdapterInterface;
use MonkeysLegion\Backup\Exception\StorageAdapterNotFoundException;
use MonkeysLegion\Backup\Storage\StorageAdapterFactory;
use MonkeysLegion\Backup\ValueObject\StorageConfig;
use PHPUnit\Framework\TestCase;

final class StorageAdapterFactoryTest extends TestCase
{
    public function testThrowsExceptionWhenAdapterDriverNotExists(): void
    {
        $config = StorageConfig::fromArray([
            'driver' => 'non_existent_driver'
        ]);

        $this->expectException(StorageAdapterNotFoundException::class);
        $this->expectExceptionMessageIsOrContains('Adapter "non_existent_driver" is not registered.');

        StorageAdapterFactory::fromConfig($config);
    }

    public function testGcsAdapterResolvesWhenClassExists(): void
    {
        // GcsStorageAdapter is now implemented; the factory must resolve it without
        // throwing StorageAdapterNotFoundException.
        // Supplying a credentialsFetcher avoids real GCP auth in this unit test.
        $config = StorageConfig::fromArray([
            'driver'             => 'gcs',
            'bucket'             => 'unit-test-bucket',
            'project_id'         => 'unit-test-project',
            'credentialsFetcher' => new \Google\Cloud\Core\AnonymousCredentials(),
        ]);

        $adapter = StorageAdapterFactory::fromConfig($config);
        $this->assertInstanceOf(StorageAdapterInterface::class, $adapter);
        $this->assertInstanceOf(\MonkeysLegion\Backup\Storage\GcsStorageAdapter::class, $adapter);
    }

    public function testS3AdapterResolvesWhenClassExists(): void
    {
        // S3StorageAdapter is now implemented; the factory must resolve it without
        // throwing StorageAdapterNotFoundException.
        $config = StorageConfig::fromArray([
            'driver'         => 's3',
            'bucket'         => 'unit-test-bucket',
            'region'         => 'us-east-1',
            'key'            => 'test-key',
            'secret'         => 'test-secret',
            'endpoint'       => 'http://localhost:9000',
            'use_path_style' => true,
        ]);

        $adapter = StorageAdapterFactory::fromConfig($config);
        $this->assertInstanceOf(StorageAdapterInterface::class, $adapter);
        $this->assertInstanceOf(\MonkeysLegion\Backup\Storage\S3StorageAdapter::class, $adapter);
    }

    public function testRegistersCustomAdapterGlobally(): void
    {
        // Define a dynamic mock or anonymous class implementing StorageAdapterInterface
        $customAdapterClass = DummyStorageAdapter::class;

        StorageAdapterFactory::register('custom_test_global', $customAdapterClass);

        $config = StorageConfig::fromArray([
            'driver' => 'custom_test_global',
            'key' => 'value'
        ]);

        $adapter = StorageAdapterFactory::fromConfig($config);
        $this->assertInstanceOf(StorageAdapterInterface::class, $adapter);
        $this->assertInstanceOf(DummyStorageAdapter::class, $adapter);
        /** @var DummyStorageAdapter $adapter */
        $this->assertSame('value', $adapter->options['key'] ?? null);
    }

    public function testRegistersCustomAdapterViaConstructor(): void
    {
        $factory = new StorageAdapterFactory([
            'custom_test_local' => DummyStorageAdapter::class
        ]);

        $adapter = $factory->create('custom_test_local', ['foo' => 'bar']);
        $this->assertInstanceOf(DummyStorageAdapter::class, $adapter);
        /** @var DummyStorageAdapter $adapter */
        $this->assertSame('bar', $adapter->options['foo'] ?? null);
    }
}

/**
 * A dummy storage adapter helper for testing.
 */
class DummyStorageAdapter implements StorageAdapterInterface
{
    /**
     * @param array<string, mixed> $options
     */
    public function __construct(
        public array $options = []
    ) {}

    public function upload(string $localPath, string $remoteKey, array $metadata = []): string
    {
        return 'uri://' . $remoteKey;
    }

    public function download(string $remoteKey, string $localPath): void {}

    public function delete(string $remoteKey): void {}

    public function list(string $prefix = ''): array
    {
        return [];
    }
}
