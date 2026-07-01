<?php

declare(strict_types=1);

namespace MonkeysLegion\Backup\Tests\Unit;

use MonkeysLegion\Backup\Storage\LocalStorageAdapter;
use PHPUnit\Framework\TestCase;

final class LocalStorageAdapterTest extends TestCase
{
    private string $tempDir;
    private string $storageDir;

    protected function setUp(): void
    {
        $this->tempDir = \sys_get_temp_dir() . '/mb_local_storage_test_' . \uniqid();
        $this->storageDir = $this->tempDir . '/storage';
        if (!\is_dir($this->tempDir)) {
            \mkdir($this->tempDir, 0755, true);
        }
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);
    }

    private function removeDirectory(string $dir): void
    {
        if (\is_dir($dir)) {
            $files = \glob("{$dir}/*");
            if ($files !== false) {
                foreach ($files as $file) {
                    if (\is_dir($file)) {
                        $this->removeDirectory($file);
                    } elseif (\is_file($file)) {
                        \unlink($file);
                    }
                }
            }
            \rmdir($dir);
        }
    }

    public function testThrowsExceptionWhenRootOptionIsMissing(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIs('LocalStorageAdapter requires a "root" directory option.');

        new LocalStorageAdapter([]);
    }

    public function testCreatesRootDirectoryOnInstantiation(): void
    {
        $targetRoot = $this->storageDir . '/new_folder';
        $this->assertDirectoryDoesNotExist($targetRoot);

        new LocalStorageAdapter(['root' => $targetRoot]);

        $this->assertDirectoryExists($targetRoot);
    }

    public function testUploadCreatesNestedDirectoriesAndCopiesFile(): void
    {
        $adapter = new LocalStorageAdapter(['root' => $this->storageDir]);

        $localFile = $this->tempDir . '/test.txt';
        $content = 'Hello Storage!';
        \file_put_contents($localFile, $content);

        // Upload with nested remote key
        $remoteKey = 'nested/sub/folder/file.txt';
        $metadata = ['compressed' => true, 'checksum' => 'abc'];

        $path = $adapter->upload($localFile, $remoteKey, $metadata);

        // Assert file exists at expected location in root
        $expectedPath = $this->storageDir . '/' . $remoteKey;
        $this->assertSame($expectedPath, $path);
        $this->assertFileExists($expectedPath);
        $this->assertSame($content, \file_get_contents($expectedPath));

        // Assert metadata sidecar exists
        $this->assertFileExists($expectedPath . '.meta');
        $metaJson = \file_get_contents($expectedPath . '.meta');
        $this->assertNotFalse($metaJson);
        $metaContent = \json_decode($metaJson, true);
        $this->assertSame($metadata, $metaContent);
    }

    public function testDownloadCopiesFileToLocalPath(): void
    {
        $adapter = new LocalStorageAdapter(['root' => $this->storageDir]);

        // Manually place file in storage root
        $remoteKey = 'folder/file.txt';
        $expectedPath = $this->storageDir . '/' . $remoteKey;
        \mkdir(\dirname($expectedPath), 0755, true);
        \file_put_contents($expectedPath, 'File Content');

        // Download to a new local file location
        $localDest = $this->tempDir . '/downloaded/nested/test.txt';
        $adapter->download($remoteKey, $localDest);

        $this->assertFileExists($localDest);
        $this->assertSame('File Content', \file_get_contents($localDest));
    }

    public function testDeleteRemovesFileAndMetadata(): void
    {
        $adapter = new LocalStorageAdapter(['root' => $this->storageDir]);

        $localFile = $this->tempDir . '/test.txt';
        \file_put_contents($localFile, 'content');

        $remoteKey = 'to_delete.txt';
        $adapter->upload($localFile, $remoteKey, ['foo' => 'bar']);

        $expectedPath = $this->storageDir . '/' . $remoteKey;
        $this->assertFileExists($expectedPath);
        $this->assertFileExists($expectedPath . '.meta');

        // Delete
        $adapter->delete($remoteKey);

        $this->assertFileDoesNotExist($expectedPath);
        $this->assertFileDoesNotExist($expectedPath . '.meta');
    }

    public function testListFiltersByPrefixAndExcludesMetaFiles(): void
    {
        $adapter = new LocalStorageAdapter(['root' => $this->storageDir]);

        // Create several files in storage
        $files = [
            'db/mysql/backup1.gz' => 'content1',
            'db/mysql/backup2.gz' => 'content2',
            'db/postgres/backup.gz' => 'content3',
            'other/file.txt' => 'content4',
        ];

        foreach ($files as $key => $content) {
            $local = $this->tempDir . '/temp.txt';
            \file_put_contents($local, $content);
            $adapter->upload($local, $key, ['has_meta' => true]);
        }

        // List all files
        $all = $adapter->list();
        // Should have 4 files (excluding meta files)
        $this->assertCount(4, $all);
        $this->assertSame('db/mysql/backup1.gz', $all[0]['key']);
        $this->assertSame('other/file.txt', $all[3]['key']);

        // List with prefix "db/"
        $dbFiles = $adapter->list('db/');
        $this->assertCount(3, $dbFiles);

        // List with prefix "db/mysql/"
        $mysqlFiles = $adapter->list('db/mysql/');
        $this->assertCount(2, $mysqlFiles);
        $this->assertSame('db/mysql/backup1.gz', $mysqlFiles[0]['key']);
        $this->assertSame('db/mysql/backup2.gz', $mysqlFiles[1]['key']);
    }

    public function testPathTraversalIsPrevented(): void
    {
        $adapter = new LocalStorageAdapter(['root' => $this->storageDir]);

        $localFile = $this->tempDir . '/test.txt';
        \file_put_contents($localFile, 'data');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('Path traversal detected');

        $adapter->upload($localFile, '../../outside.txt');
    }

    public function testUploadThrowsOnInvalidLocalFile(): void
    {
        $adapter = new LocalStorageAdapter(['root' => $this->storageDir]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageIsOrContains('Local file is not readable');

        $adapter->upload('/nonexistent/file', 'test.txt');
    }

    public function testDownloadThrowsOnMissingRemoteFile(): void
    {
        $adapter = new LocalStorageAdapter(['root' => $this->storageDir]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageIsOrContains('Remote file not found or not readable');

        $adapter->download('nonexistent.txt', $this->tempDir . '/dest.txt');
    }

    public function testDownloadThrowsWhenDirectoryCreationFails(): void
    {
        $adapter = new LocalStorageAdapter(['root' => $this->storageDir]);

        // Create a remote file
        $local = $this->tempDir . '/src.txt';
        \file_put_contents($local, 'data');
        $adapter->upload($local, 'remote.txt');

        // Create a file at the path where directory should be created
        $conflictFile = $this->tempDir . '/conflict_file';
        \file_put_contents($conflictFile, 'blocking file');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageIsOrContains('Failed to create directory');

        $adapter->download('remote.txt', $conflictFile . '/dest.txt');
    }

    public function testInstantiationThrowsWhenRootIsFile(): void
    {
        $filePath = $this->tempDir . '/blocking_file';
        \file_put_contents($filePath, 'content');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageIsOrContains('Failed to create root directory');

        new LocalStorageAdapter(['root' => $filePath]);
    }

    public function testListReturnsEmptyArrayWhenRootIsDeleted(): void
    {
        $adapter = new LocalStorageAdapter(['root' => $this->storageDir]);
        $this->assertDirectoryExists($this->storageDir);

        $this->removeDirectory($this->storageDir);

        $this->assertSame([], $adapter->list());
    }
}
