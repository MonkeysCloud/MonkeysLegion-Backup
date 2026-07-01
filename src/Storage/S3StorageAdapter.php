<?php

declare(strict_types=1);

namespace MonkeysLegion\Backup\Storage;

use Aws\S3\Exception\S3Exception;
use Aws\S3\S3Client;
use MonkeysLegion\Backup\Contract\StorageAdapterInterface;

/**
 * AWS S3 / MinIO compatible storage adapter.
 *
 * Uploads, downloads, deletes, and lists backup artifacts in an S3 bucket.
 * Metadata sidecars are stored as a separate object with a `.meta` suffix.
 *
 * Configuration keys:
 *   - bucket          (required) S3 bucket name.
 *   - region          (required) AWS region (e.g. "us-east-1", "eu-west-1").
 *   - key             (required) AWS access key ID.
 *   - secret          (required) AWS secret access key.
 *   - endpoint        (optional) Custom endpoint URL — enables MinIO/LocalStack compatibility.
 *   - use_path_style  (optional, bool) Force path-style URLs; required for MinIO (default: false).
 *   - prefix          (optional) Object key prefix applied to every operation.
 */
final class S3StorageAdapter implements StorageAdapterInterface
{
    private S3Client $client;
    private string $bucket;
    private string $prefix;

    /**
     * @param array<string, mixed> $options
     */
    public function __construct(array $options)
    {
        $bucket = $options['bucket'] ?? null;
        if ($bucket === null || $bucket === '') {
            throw new \InvalidArgumentException('S3StorageAdapter requires a "bucket" option.');
        }

        $region = $options['region'] ?? null;
        if ($region === null || $region === '') {
            throw new \InvalidArgumentException('S3StorageAdapter requires a "region" option.');
        }

        $key    = $options['key'] ?? null;
        $secret = $options['secret'] ?? null;
        if ($key === null || $key === '' || $secret === null || $secret === '') {
            throw new \InvalidArgumentException('S3StorageAdapter requires "key" and "secret" options.');
        }

        $this->bucket = (string)$bucket;
        $this->prefix = isset($options['prefix']) ? \rtrim((string)$options['prefix'], '/') . '/' : '';

        $clientConfig = [
            'version'     => 'latest',
            'region'      => (string)$region,
            'credentials' => [
                'key'    => (string)$key,
                'secret' => (string)$secret,
            ],
        ];

        if (isset($options['endpoint']) && (string)$options['endpoint'] !== '') {
            $clientConfig['endpoint'] = (string)$options['endpoint'];
        }

        if (!empty($options['use_path_style'])) {
            $clientConfig['use_path_style_endpoint'] = true;
        }

        $this->client = new S3Client($clientConfig);
    }

    // -------------------------------------------------------------------------
    // StorageAdapterInterface
    // -------------------------------------------------------------------------

    public function upload(string $localPath, string $remoteKey, array $metadata = []): string
    {
        if (!\is_file($localPath) || !\is_readable($localPath)) {
            throw new \RuntimeException("Local file is not readable: {$localPath}");
        }

        $objectKey = $this->prefixed($remoteKey);

        try {
            $this->client->putObject([
                'Bucket'     => $this->bucket,
                'Key'        => $objectKey,
                'SourceFile' => $localPath,
            ]);

            if (!empty($metadata)) {
                $metaKey = "{$objectKey}.meta";
                $this->client->putObject([
                    'Bucket'      => $this->bucket,
                    'Key'         => $metaKey,
                    'Body'        => \json_encode($metadata, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR),
                    'ContentType' => 'application/json',
                ]);
            }
        } catch (S3Exception $e) {
            throw new \RuntimeException("Failed to upload object \"{$objectKey}\" to S3: {$e->getAwsErrorMessage()}", 0, $e);
        }

        return "s3://{$this->bucket}/{$objectKey}";
    }

    public function download(string $remoteKey, string $localPath): void
    {
        $objectKey = $this->prefixed($remoteKey);

        $dir = \dirname($localPath);
        if (!\is_dir($dir) && !@\mkdir($dir, 0755, true) && !\is_dir($dir)) {
            throw new \RuntimeException("Failed to create directory: {$dir}");
        }

        $tmpFile = "{$localPath}." . \uniqid('', true) . '.tmp';

        try {
            $this->client->getObject([
                'Bucket' => $this->bucket,
                'Key'    => $objectKey,
                'SaveAs' => $tmpFile,
            ]);
        } catch (S3Exception $e) {
            @\unlink($tmpFile);
            throw new \RuntimeException("Failed to download object \"{$objectKey}\" from S3: {$e->getAwsErrorMessage()}", 0, $e);
        }

        if (!@\rename($tmpFile, $localPath)) {
            @\unlink($tmpFile);
            throw new \RuntimeException("Failed to rename downloaded temp file to: {$localPath}");
        }
    }

    public function delete(string $remoteKey): void
    {
        $objectKey = $this->prefixed($remoteKey);

        try {
            $this->client->deleteObject([
                'Bucket' => $this->bucket,
                'Key'    => $objectKey,
            ]);

            $metaKey = "{$objectKey}.meta";
            $this->client->deleteObject([
                'Bucket' => $this->bucket,
                'Key'    => $metaKey,
            ]);
        } catch (S3Exception $e) {
            throw new \RuntimeException("Failed to delete object \"{$objectKey}\" from S3: {$e->getAwsErrorMessage()}", 0, $e);
        }
    }

    public function list(string $prefix = ''): array
    {
        $fullPrefix = $this->prefixed($prefix);
        $results    = [];

        try {
            $paginator = $this->client->getPaginator('ListObjectsV2', [
                'Bucket' => $this->bucket,
                'Prefix' => $fullPrefix,
            ]);

            foreach ($paginator as $page) {
                /** @var array<int, array<string, mixed>> $contents */
                $contents = $page['Contents'] ?? [];
                foreach ($contents as $item) {
                    $key = (string)($item['Key'] ?? '');

                    // Skip metadata sidecar files
                    if (\str_ends_with($key, '.meta')) {
                        continue;
                    }

                    $relativeKey = $this->prefix !== ''
                        ? \substr($key, \strlen($this->prefix))
                        : $key;

                    /** @var \DateTimeInterface|null $lastModified */
                    $lastModified = $item['LastModified'] ?? null;

                    $results[] = [
                        'key'         => $relativeKey,
                        'size'        => (int)($item['Size'] ?? 0),
                        'modified_at' => $lastModified instanceof \DateTimeInterface
                            ? $lastModified->format('c')
                            : '',
                    ];
                }
            }
        } catch (S3Exception $e) {
            throw new \RuntimeException("Failed to list objects in S3 bucket \"{$this->bucket}\": {$e->getAwsErrorMessage()}", 0, $e);
        }

        \usort($results, fn ($a, $b) => $a['key'] <=> $b['key']);

        return $results;
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function prefixed(string $key): string
    {
        $safeKey = \str_replace(['..', "\0"], '', $key);
        $safeKey = \ltrim(\str_replace('\\', '/', $safeKey), '/');
        return "{$this->prefix}{$safeKey}";
    }
}
