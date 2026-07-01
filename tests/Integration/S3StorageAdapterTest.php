<?php

declare(strict_types=1);

namespace MonkeysLegion\Backup\Tests\Integration;

use MonkeysLegion\Backup\Storage\S3StorageAdapter;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Integration tests for S3StorageAdapter against a local MinIO instance.
 *
 * Prerequisites (managed via docker-compose.testing.yml):
 *   docker compose -f docker-compose.testing.yml up -d --wait
 *
 * Run only this group:
 *   vendor/bin/phpunit --group s3
 */
#[Group('s3')]
final class S3StorageAdapterTest extends TestCase
{
    private const string S3_ENDPOINT = 'http://localhost:9000';
    private const string S3_REGION   = 'us-east-1';
    private const string S3_KEY      = 'minioadmin';
    private const string S3_SECRET   = 'minioadmin';
    private const string S3_BUCKET   = 'test-backup-bucket';

    private S3StorageAdapter $adapter;
    private string $tempDir;

    protected function setUp(): void
    {
        if (!$this->isMinioReachable()) {
            $this->markTestSkipped(
                'MinIO is not running. Start it with: ' .
                'docker compose -f docker-compose.testing.yml up -d --wait'
            );
        }

        $this->tempDir = \sys_get_temp_dir() . '/mb_s3_integration_' . \uniqid();
        \mkdir($this->tempDir, 0755, true);

        $this->adapter = $this->buildAdapter();
        $this->ensureBucket();
    }

    protected function tearDown(): void
    {
        if (\is_dir($this->tempDir)) {
            $files = \glob("{$this->tempDir}/*") ?: [];
            foreach ($files as $file) {
                if (\is_file($file)) {
                    \unlink($file);
                }
            }
            \rmdir($this->tempDir);
        }
    }

    // -------------------------------------------------------------------------
    // Tests
    // -------------------------------------------------------------------------

    public function testUploadAndDownloadRoundtrip(): void
    {
        $localFile = "{$this->tempDir}/backup.sql";
        \file_put_contents($localFile, 'SELECT 1; -- s3 backup content');

        $remoteKey = "integration/test_upload_download_{$this->uniq()}.sql";

        $uri = $this->adapter->upload($localFile, $remoteKey);
        $this->assertStringContainsString($remoteKey, $uri);

        $downloadPath = "{$this->tempDir}/downloaded.sql";
        $this->adapter->download($remoteKey, $downloadPath);

        $this->assertFileExists($downloadPath);
        $this->assertSame('SELECT 1; -- s3 backup content', \file_get_contents($downloadPath));

        $this->adapter->delete($remoteKey);
    }

    public function testUploadStoresMetadataSidecar(): void
    {
        $localFile = "{$this->tempDir}/backup_meta.sql";
        \file_put_contents($localFile, 'backup data');

        $remoteKey = "integration/test_meta_{$this->uniq()}.sql";
        $metadata  = ['engine' => 'postgres', 'checksum' => 'deadbeef', 'compressed' => true];

        $this->adapter->upload($localFile, $remoteKey, $metadata);

        $metaDownload = "{$this->tempDir}/meta.json";
        $this->adapter->download("{$remoteKey}.meta", $metaDownload);

        $decoded = \json_decode(\file_get_contents($metaDownload) ?: '{}', true);
        $this->assertSame('postgres', $decoded['engine'] ?? null);
        $this->assertSame('deadbeef', $decoded['checksum'] ?? null);
        $this->assertTrue($decoded['compressed'] ?? false);

        $this->adapter->delete($remoteKey);
    }

    public function testDeleteRemovesObjectAndMeta(): void
    {
        $localFile = "{$this->tempDir}/backup_del.sql";
        \file_put_contents($localFile, 'delete me');

        $remoteKey = "integration/test_delete_{$this->uniq()}.sql";
        $this->adapter->upload($localFile, $remoteKey, ['engine' => 'mysql']);

        $this->adapter->delete($remoteKey);

        $this->expectException(\RuntimeException::class);
        $this->adapter->download($remoteKey, "{$this->tempDir}/should_not_exist.sql");
    }

    public function testListReturnsUploadedObjects(): void
    {
        $prefix = "integration/list_test_{$this->uniq()}";

        $fileA = "{$this->tempDir}/a.sql";
        $fileB = "{$this->tempDir}/b.sql";
        \file_put_contents($fileA, 'aaa');
        \file_put_contents($fileB, 'bbb');

        $keyA = "{$prefix}/a.sql";
        $keyB = "{$prefix}/b.sql";
        $this->adapter->upload($fileA, $keyA);
        $this->adapter->upload($fileB, $keyB);

        $items = $this->adapter->list($prefix);
        $keys  = \array_column($items, 'key');

        $this->assertContains($keyA, $keys);
        $this->assertContains($keyB, $keys);
        // .meta files must NOT appear in the listing
        $this->assertEmpty(\array_filter($keys, fn ($k) => \str_ends_with($k, '.meta')));

        $this->adapter->delete($keyA);
        $this->adapter->delete($keyB);
    }

    public function testListWithPrefixFilters(): void
    {
        $prefix = "integration/prefix_test_{$this->uniq()}";

        $fileA = "{$this->tempDir}/p_a.sql";
        $fileB = "{$this->tempDir}/p_b.sql";
        \file_put_contents($fileA, 'a');
        \file_put_contents($fileB, 'b');

        $keyA = "{$prefix}/a.sql";
        $keyB = "{$prefix}/b.sql";
        $this->adapter->upload($fileA, $keyA);
        $this->adapter->upload($fileB, $keyB);

        // List only keyA via a more specific prefix
        $items = $this->adapter->list("{$prefix}/a");
        $keys  = \array_column($items, 'key');
        $this->assertContains($keyA, $keys);
        $this->assertNotContains($keyB, $keys);

        $this->adapter->delete($keyA);
        $this->adapter->delete($keyB);
    }

    public function testChecksumIntegrity(): void
    {
        $content   = \str_repeat('s3-integrity-check ', 500);
        $localFile = "{$this->tempDir}/integrity.sql";
        \file_put_contents($localFile, $content);

        $expectedChecksum = \hash('sha256', $content);
        $remoteKey        = "integration/checksum_{$this->uniq()}.sql";

        $this->adapter->upload($localFile, $remoteKey);

        $downloadPath = "{$this->tempDir}/integrity_dl.sql";
        $this->adapter->download($remoteKey, $downloadPath);

        $this->assertSame($expectedChecksum, \hash_file('sha256', $downloadPath));

        $this->adapter->delete($remoteKey);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function buildAdapter(): S3StorageAdapter
    {
        return new S3StorageAdapter([
            'bucket'         => self::S3_BUCKET,
            'region'         => self::S3_REGION,
            'key'            => self::S3_KEY,
            'secret'         => self::S3_SECRET,
            'endpoint'       => self::S3_ENDPOINT,
            'use_path_style' => true,
        ]);
    }

    private function ensureBucket(): void
    {
        $client = new \Aws\S3\S3Client([
            'version'                 => 'latest',
            'region'                  => self::S3_REGION,
            'endpoint'                => self::S3_ENDPOINT,
            'use_path_style_endpoint' => true,
            'credentials'             => [
                'key'    => self::S3_KEY,
                'secret' => self::S3_SECRET,
            ],
        ]);

        if (!$client->doesBucketExistV2(self::S3_BUCKET)) {
            $client->createBucket(['Bucket' => self::S3_BUCKET]);
        }
    }

    private function isMinioReachable(): bool
    {
        $ctx = \stream_context_create(['http' => ['timeout' => 2]]);
        // MinIO health endpoint
        return @\file_get_contents(self::S3_ENDPOINT . '/minio/health/live', false, $ctx) !== false;
    }

    private function uniq(): string
    {
        return \uniqid('', true);
    }
}
