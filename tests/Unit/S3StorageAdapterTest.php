<?php

declare(strict_types=1);

namespace MonkeysLegion\Backup\Tests\Unit;

use Aws\CommandInterface;
use Aws\MockHandler;
use Aws\Result;
use Aws\S3\S3Client;
use GuzzleHttp\Promise;
use MonkeysLegion\Backup\Storage\S3StorageAdapter;
use PHPUnit\Framework\TestCase;

final class S3StorageAdapterTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = \sys_get_temp_dir() . '/mb_s3_test_' . \uniqid();
        \mkdir($this->tempDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tempDir);
    }

    public function testConstructorValidation(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new S3StorageAdapter([]);
    }

    public function testConstructorValidationRegion(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new S3StorageAdapter(['bucket' => 'bucket']);
    }

    public function testConstructorValidationCredentials(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new S3StorageAdapter(['bucket' => 'bucket', 'region' => 'us-east-1']);
    }

    public function testUploadFilesAndMetadata(): void
    {
        $localFile = "{$this->tempDir}/test.txt";
        \file_put_contents($localFile, 'hello s3');

        $mockHandler = new MockHandler();
        $mockHandler->append(new Result(['@metadata' => ['statusCode' => 200]]));
        $mockHandler->append(new Result(['@metadata' => ['statusCode' => 200]]));

        $client = new S3Client([
            'version' => 'latest',
            'region' => 'us-east-1',
            'credentials' => ['key' => 'k', 'secret' => 's'],
            'handler' => $mockHandler
        ]);

        $adapter = new S3StorageAdapter([
            'bucket' => 'test-bucket',
            'region' => 'us-east-1',
            'key' => 'k',
            'secret' => 's',
            'prefix' => 'prefix',
            'endpoint' => 'http://localhost:9000',
            'use_path_style' => true
        ]);

        $this->setPrivateProperty($adapter, 'client', $client);

        $uri = $adapter->upload($localFile, 'test.txt', ['foo' => 'bar']);
        $this->assertSame('s3://test-bucket/prefix/test.txt', $uri);
    }

    public function testUploadThrowsOnInvalidLocalFile(): void
    {
        $adapter = new S3StorageAdapter([
            'bucket' => 'test-bucket',
            'region' => 'us-east-1',
            'key' => 'k',
            'secret' => 's'
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageIsOrContains('Local file is not readable');

        $adapter->upload('/nonexistent/file', 'test.txt');
    }

    public function testDownloadObject(): void
    {
        $localDest = "{$this->tempDir}/downloaded.txt";

        // We use a custom handler to simulate writing the file locally
        $handler = function (CommandInterface $command) {
            if ($command->getName() === 'GetObject') {
                $saveAs = $command['@http']['sink'] ?? null;
                if ($saveAs !== null) {
                    \file_put_contents($saveAs, 's3 content');
                }
            }
            return Promise\Create::promiseFor(new Result([]));
        };

        $client = new S3Client([
            'version' => 'latest',
            'region' => 'us-east-1',
            'credentials' => ['key' => 'k', 'secret' => 's'],
            'handler' => $handler
        ]);

        $adapter = new S3StorageAdapter([
            'bucket' => 'test-bucket',
            'region' => 'us-east-1',
            'key' => 'k',
            'secret' => 's'
        ]);

        $this->setPrivateProperty($adapter, 'client', $client);

        $adapter->download('test.txt', $localDest);

        $this->assertFileExists($localDest);
        $this->assertSame('s3 content', \file_get_contents($localDest));
    }

    public function testDeleteObject(): void
    {
        $client = $this->getMockBuilder(S3Client::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['__call'])
            ->getMock();

        $callCount = 0;

        $client->expects($this->exactly(2))
            ->method('__call')
            ->willReturnCallback(function (string $name, array $args) use (&$callCount) {
                $callCount++;

                $this->assertEquals('deleteObject', $name);

                if ($callCount === 1) {
                    $this->assertEquals('test.txt', $args[0]['Key']);
                } else {
                    $this->assertEquals('test.txt.meta', $args[0]['Key']);
                }

                return new Result([]);
            });

        $adapter = new S3StorageAdapter([
            'bucket' => 'test-bucket',
            'region' => 'us-east-1',
            'key' => 'k',
            'secret' => 's'
        ]);

        $this->setPrivateProperty($adapter, 'client', $client);

        $adapter->delete('test.txt');
    }

    public function testListObjects(): void
    {
        $mockHandler = new MockHandler();
        $mockHandler->append(new Result([
            'IsTruncated' => false,
            'Contents' => [
                [
                    'Key' => 'prefix/b.txt',
                    'Size' => 100,
                    'LastModified' => new \Aws\Api\DateTimeResult('2026-07-01T12:00:00Z')
                ],
                [
                    'Key' => 'prefix/a.txt',
                    'Size' => 200,
                    'LastModified' => new \Aws\Api\DateTimeResult('2026-07-01T13:00:00Z')
                ],
                [
                    'Key' => 'prefix/a.txt.meta',
                    'Size' => 50,
                    'LastModified' => new \Aws\Api\DateTimeResult('2026-07-01T13:00:00Z')
                ]
            ]
        ]));

        $client = new S3Client([
            'version' => 'latest',
            'region' => 'us-east-1',
            'credentials' => ['key' => 'k', 'secret' => 's'],
            'handler' => $mockHandler
        ]);

        $adapter = new S3StorageAdapter([
            'bucket' => 'test-bucket',
            'region' => 'us-east-1',
            'key' => 'k',
            'secret' => 's',
            'prefix' => 'prefix/'
        ]);

        $this->setPrivateProperty($adapter, 'client', $client);

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
