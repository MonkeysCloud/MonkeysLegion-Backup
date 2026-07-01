<?php

declare(strict_types=1);

namespace MonkeysLegion\Backup\Tests\Integration;

use MonkeysLegion\Backup\Storage\GcsStorageAdapter;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Integration test for GcsStorageAdapter against a real fake-gcs-server instance.
 *
 * Prerequisites (managed via docker-compose.testing.yml):
 *   docker compose -f docker-compose.testing.yml up -d --wait
 *
 * Run only this group:
 *   vendor/bin/phpunit --group gcs
 */
#[Group('gcs')]
final class GcsStorageAdapterTest extends TestCase
{
    private const string GCS_ENDPOINT  = 'http://localhost:4443';
    private const string GCS_BUCKET    = 'test-backup-bucket';
    private const string GCS_PROJECT   = 'test-project';

    private GcsStorageAdapter $adapter;
    private string $tempDir;

    protected function setUp(): void
    {
        if (!$this->isFakeGcsReachable()) {
            $this->markTestSkipped(
                'fake-gcs-server is not running. Start it with: ' .
                'docker compose -f docker-compose.testing.yml up -d --wait'
            );
        }

        $this->tempDir = \sys_get_temp_dir() . '/mb_gcs_integration_' . \uniqid();
        \mkdir($this->tempDir, 0755, true);

        $this->adapter = $this->buildAdapter();

        $this->ensureBucket();
    }

    protected function tearDown(): void
    {
        // Clean up local temp files
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
        \file_put_contents($localFile, 'SELECT 1; -- backup content');

        $remoteKey = "integration/test_upload_download_{$this->uniq()}.sql";

        $uri = $this->adapter->upload($localFile, $remoteKey);
        $this->assertStringContainsString($remoteKey, $uri);

        $downloadPath = "{$this->tempDir}/downloaded.sql";
        $this->adapter->download($remoteKey, $downloadPath);

        $this->assertFileExists($downloadPath);
        $this->assertSame('SELECT 1; -- backup content', \file_get_contents($downloadPath));

        $this->adapter->delete($remoteKey);
    }

    public function testUploadStoresMetadataSidecar(): void
    {
        $localFile = "{$this->tempDir}/backup_meta.sql";
        \file_put_contents($localFile, 'backup data');

        $remoteKey = "integration/test_meta_{$this->uniq()}.sql";
        $metadata  = ['engine' => 'mysql', 'checksum' => 'abc123', 'compressed' => false];

        $this->adapter->upload($localFile, $remoteKey, $metadata);

        // Download the sidecar and verify its content
        $metaDownload = "{$this->tempDir}/meta.json";
        $this->adapter->download("{$remoteKey}.meta", $metaDownload);

        $decoded = \json_decode(\file_get_contents($metaDownload) ?: '{}', true);
        $this->assertSame('mysql', $decoded['engine'] ?? null);
        $this->assertSame('abc123', $decoded['checksum'] ?? null);
        $this->assertFalse($decoded['compressed'] ?? true);

        $this->adapter->delete($remoteKey);
    }

    public function testDeleteRemovesObjectAndMeta(): void
    {
        $localFile = "{$this->tempDir}/backup_del.sql";
        \file_put_contents($localFile, 'delete me');

        $remoteKey = "integration/test_delete_{$this->uniq()}.sql";
        $this->adapter->upload($localFile, $remoteKey, ['engine' => 'sqlite']);

        $this->adapter->delete($remoteKey);

        // Attempting to download after deletion should throw
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

        $keys = \array_column($items, 'key');
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

        // List only keyA by using a more specific prefix
        $items = $this->adapter->list("{$prefix}/a");
        $keys  = \array_column($items, 'key');
        $this->assertContains($keyA, $keys);
        $this->assertNotContains($keyB, $keys);

        $this->adapter->delete($keyA);
        $this->adapter->delete($keyB);
    }

    public function testChecksumIntegrity(): void
    {
        $content  = \str_repeat('integrity-check ', 500);
        $localFile = "{$this->tempDir}/integrity.sql";
        \file_put_contents($localFile, $content);

        $expectedChecksum = \hash('sha256', $content);
        $remoteKey = "integration/checksum_{$this->uniq()}.sql";

        $this->adapter->upload($localFile, $remoteKey);

        $downloadPath = "{$this->tempDir}/integrity_dl.sql";
        $this->adapter->download($remoteKey, $downloadPath);

        $this->assertSame($expectedChecksum, \hash_file('sha256', $downloadPath));

        $this->adapter->delete($remoteKey);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function buildAdapter(): GcsStorageAdapter
    {
        // AnonymousCredentials + explicit endpoint routes all requests to the
        // emulator with no auth — no ADC or real key file required.
        return new GcsStorageAdapter([
            'bucket'             => self::GCS_BUCKET,
            'project_id'         => self::GCS_PROJECT,
            'endpoint'           => self::GCS_ENDPOINT,
            'credentialsFetcher' => new \Google\Cloud\Core\AnonymousCredentials(),
        ]);
    }

    private function ensureBucket(): void
    {
        // Ensure the bucket exists before tests; fake-gcs-server will auto-
        // create it on the first upload but we prime it here to be safe.
        $client = new \Google\Cloud\Storage\StorageClient([
            'projectId'          => self::GCS_PROJECT,
            'apiEndpoint'        => self::GCS_ENDPOINT,
            'credentialsFetcher' => new \Google\Cloud\Core\AnonymousCredentials(),
        ]);

        $bucket = $client->bucket(self::GCS_BUCKET);
        if (!$bucket->exists()) {
            $client->createBucket(self::GCS_BUCKET);
        }
    }

    private function isFakeGcsReachable(): bool
    {
        $url = self::GCS_ENDPOINT . '/_internal/healthcheck';
        $ctx = \stream_context_create(['http' => ['timeout' => 2]]);
        return @\file_get_contents($url, false, $ctx) !== false;
    }

    private function uniq(): string
    {
        return \uniqid('', true);
    }
}
