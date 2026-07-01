<?php

declare(strict_types=1);

namespace MonkeysLegion\Backup\Tests\Unit;

use Google\Cloud\Storage\Bucket;
use Google\Cloud\Storage\StorageClient;
use Google\Cloud\Storage\StorageObject;
use MonkeysLegion\Backup\Storage\GcsStorageAdapter;
use PHPUnit\Framework\TestCase;

final class GcsStorageAdapterTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = \sys_get_temp_dir() . '/mb_gcs_test_' . \uniqid();
        \mkdir($this->tempDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tempDir);
    }

    public function testThrowsExceptionWhenBucketOptionIsMissing(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('GcsStorageAdapter requires a "bucket" option.');

        new GcsStorageAdapter([]);
    }

    public function testInstantiationWithVariousOptions(): void
    {
        $adapter = new GcsStorageAdapter([
            'bucket' => 'test-bucket',
            'project_id' => 'test-project',
            'prefix' => 'my-prefix/',
            'key_file_array' => ['type' => 'service_account'],
            'credentialsFetcher' => new \Google\Cloud\Core\AnonymousCredentials(),
        ]);

        $this->assertInstanceOf(GcsStorageAdapter::class, $adapter);
    }

    public function testUploadFilesAndMetadata(): void
    {
        $localFile = "{$this->tempDir}/test.txt";
        \file_put_contents($localFile, 'hello gcs');

        $mockClient = $this->createMock(StorageClient::class);
        $mockBucket = $this->createMock(Bucket::class);

        $mockClient->expects($this->once())
            ->method('bucket')
            ->with('test-bucket')
            ->willReturn($mockBucket);

        $uploaded = [];
        $mockBucket->expects($this->exactly(2))
            ->method('upload')
            ->willReturnCallback(function ($data, $options) use (&$uploaded) {
                $uploaded[] = [$data, $options];
                return $this->createMock(StorageObject::class);
            });

        $adapter = new GcsStorageAdapter([
            'bucket' => 'test-bucket',
            'prefix' => 'prefix',
            'credentialsFetcher' => new \Google\Cloud\Core\AnonymousCredentials(),
        ]);

        $this->setPrivateProperty($adapter, 'client', $mockClient);

        $uri = $adapter->upload($localFile, 'test.txt', ['foo' => 'bar']);
        $this->assertSame('gs://test-bucket/prefix/test.txt', $uri);

        $this->assertCount(2, $uploaded);
        $this->assertSame('prefix/test.txt', $uploaded[0][1]['name']);
        $this->assertSame('prefix/test.txt.meta', $uploaded[1][1]['name']);
    }

    public function testUploadThrowsOnInvalidLocalFile(): void
    {
        $adapter = new GcsStorageAdapter([
            'bucket' => 'test-bucket',
            'credentialsFetcher' => new \Google\Cloud\Core\AnonymousCredentials(),
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageIsOrContains('Local file is not readable');

        $adapter->upload('/nonexistent/file', 'test.txt');
    }

    public function testDownloadObject(): void
    {
        $localDest = "{$this->tempDir}/downloaded.txt";

        $mockClient = $this->createMock(StorageClient::class);
        $mockBucket = $this->createMock(Bucket::class);
        $mockObject = $this->createMock(StorageObject::class);

        $mockClient->method('bucket')->willReturn($mockBucket);
        $mockBucket->method('object')->with('test.txt')->willReturn($mockObject);

        $mockObject->expects($this->once())
            ->method('downloadToFile')
            ->willReturnCallback(function ($path) {
                \file_put_contents($path, 'gcs content');
                return true;
            });

        $adapter = new GcsStorageAdapter([
            'bucket' => 'test-bucket',
            'credentialsFetcher' => new \Google\Cloud\Core\AnonymousCredentials(),
        ]);

        $this->setPrivateProperty($adapter, 'client', $mockClient);

        $adapter->download('test.txt', $localDest);

        $this->assertFileExists($localDest);
        $this->assertSame('gcs content', \file_get_contents($localDest));
    }

    public function testDownloadThrowsOnFailure(): void
    {
        $localDest = "{$this->tempDir}/downloaded.txt";

        $mockClient = $this->createMock(StorageClient::class);
        $mockBucket = $this->createMock(Bucket::class);
        $mockObject = $this->createMock(StorageObject::class);

        $mockClient->method('bucket')->willReturn($mockBucket);
        $mockBucket->method('object')->willReturn($mockObject);

        $mockObject->method('downloadToFile')->willThrowException(new \RuntimeException('GCS error'));

        $adapter = new GcsStorageAdapter([
            'bucket' => 'test-bucket',
            'credentialsFetcher' => new \Google\Cloud\Core\AnonymousCredentials(),
        ]);

        $this->setPrivateProperty($adapter, 'client', $mockClient);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageIsOrContains('Failed to download object');

        $adapter->download('test.txt', $localDest);
    }

    public function testDeleteObjects(): void
    {
        $mockClient = $this->createMock(StorageClient::class);
        $mockBucket = $this->createMock(Bucket::class);
        $mockObj = $this->createMock(StorageObject::class);
        $mockMetaObj = $this->createMock(StorageObject::class);

        $mockClient->method('bucket')->willReturn($mockBucket);

        $mockBucket->expects($this->exactly(4))
            ->method('object')
            ->willReturnCallback(function ($name) use ($mockObj, $mockMetaObj) {
                if (\str_ends_with($name, '.meta')) {
                    return $mockMetaObj;
                }
                return $mockObj;
            });

        $mockObj->expects($this->once())->method('exists')->willReturn(true);
        $mockObj->expects($this->once())->method('delete');

        $mockMetaObj->expects($this->once())->method('exists')->willReturn(true);
        $mockMetaObj->expects($this->once())->method('delete');

        $adapter = new GcsStorageAdapter([
            'bucket' => 'test-bucket',
            'credentialsFetcher' => new \Google\Cloud\Core\AnonymousCredentials(),
        ]);

        $this->setPrivateProperty($adapter, 'client', $mockClient);

        $adapter->delete('test.txt');
    }

    public function testListObjects(): void
    {
        $mockClient = $this->createMock(StorageClient::class);
        $mockBucket = $this->createMock(Bucket::class);

        $mockObj1 = $this->createMock(StorageObject::class);
        $mockObj1->method('name')->willReturn('b.txt');
        $mockObj1->method('info')->willReturn(['size' => 100, 'updated' => '2026-07-01T12:00:00Z']);

        $mockObj2 = $this->createMock(StorageObject::class);
        $mockObj2->method('name')->willReturn('a.txt');
        $mockObj2->method('info')->willReturn(['size' => 200, 'updated' => '2026-07-01T13:00:00Z']);

        // A metadata file should be skipped
        $mockObjMeta = $this->createMock(StorageObject::class);
        $mockObjMeta->method('name')->willReturn('a.txt.meta');

        $mockClient->method('bucket')->willReturn($mockBucket);
        $mockBucket->method('objects')
            ->with(['prefix' => ''])
            ->willReturn([$mockObj1, $mockObj2, $mockObjMeta]);

        $adapter = new GcsStorageAdapter([
            'bucket' => 'test-bucket',
            'credentialsFetcher' => new \Google\Cloud\Core\AnonymousCredentials(),
        ]);

        $this->setPrivateProperty($adapter, 'client', $mockClient);

        $list = $adapter->list();

        $this->assertCount(2, $list);
        $this->assertSame('a.txt', $list[0]['key']);
        $this->assertSame(200, $list[0]['size']);
        $this->assertSame('b.txt', $list[1]['key']);
        $this->assertSame(100, $list[1]['size']);
    }

    private function setPrivateProperty(object $object, string $property, mixed $value): void
    {
        $ref = new \ReflectionClass($object);
        $prop = $ref->getProperty($property);
        $prop->setAccessible(true);
        $prop->setValue($object, $value);
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
