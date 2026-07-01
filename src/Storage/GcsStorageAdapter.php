<?php

declare(strict_types=1);

namespace MonkeysLegion\Backup\Storage;

use Google\Cloud\Storage\StorageClient;
use MonkeysLegion\Backup\Contract\StorageAdapterInterface;

/**
 * Google Cloud Storage adapter.
 *
 * Uploads, downloads, deletes, and lists backup artifacts in a GCS bucket.
 * Metadata sidecars are stored as a separate object with a `.meta` suffix.
 *
 * Configuration keys:
 *   - bucket       (required) GCS bucket name.
 *   - project_id   (optional) GCP project ID.
 *   - key_file     (optional) Path to a service-account JSON key file.
 *   - endpoint     (optional) Custom API endpoint (useful for fake-gcs-server in tests).
 *   - prefix       (optional) Object key prefix applied to every operation.
 */
final class GcsStorageAdapter implements StorageAdapterInterface
{
    private StorageClient $client;
    private string $bucket;
    private string $prefix;

    /**
     * @param array<string, mixed> $options
     */
    public function __construct(array $options)
    {
        $bucket = $options['bucket'] ?? null;
        if ($bucket === null || $bucket === '') {
            throw new \InvalidArgumentException('GcsStorageAdapter requires a "bucket" option.');
        }

        $this->bucket = (string)$bucket;
        $this->prefix = isset($options['prefix']) ? \rtrim((string)$options['prefix'], '/') . '/' : '';

        $clientOptions = [];

        if (isset($options['project_id'])) {
            $clientOptions['projectId'] = (string)$options['project_id'];
        }

        if (isset($options['key_file']) && \is_file((string)$options['key_file'])) {
            $clientOptions['keyFilePath'] = (string)$options['key_file'];
        }

        if (isset($options['endpoint'])) {
            $clientOptions['apiEndpoint'] = (string)$options['endpoint'];
        }

        // Allow keyFile array (e.g. emulator / test credentials) to suppress
        // application-default-credential lookup entirely.
        if (isset($options['key_file_array']) && \is_array($options['key_file_array'])) {
            /** @var array<string, mixed> $keyArr */
            $keyArr = $options['key_file_array'];
            $clientOptions['keyFile'] = $keyArr;
        }

        // Allow passing a pre-built credentialsFetcher (e.g. AnonymousCredentials
        // for fake-gcs-server in integration tests).
        if (isset($options['credentialsFetcher'])) {
            $clientOptions['credentialsFetcher'] = $options['credentialsFetcher'];
        }

        $this->client = new StorageClient($clientOptions);
    }

    // -------------------------------------------------------------------------
    // StorageAdapterInterface
    // -------------------------------------------------------------------------

    public function upload(string $localPath, string $remoteKey, array $metadata = []): string
    {
        if (!\is_file($localPath) || !\is_readable($localPath)) {
            throw new \RuntimeException("Local file is not readable: {$localPath}");
        }

        $objectName = $this->prefixed($remoteKey);
        $bucket = $this->client->bucket($this->bucket);

        $stream = \fopen($localPath, 'rb');
        if ($stream === false) {
            throw new \RuntimeException("Cannot open file for reading: {$localPath}");
        }

        try {
            $bucket->upload($stream, ['name' => $objectName]);
        } finally {
            if (\is_resource($stream)) {
                \fclose($stream);
            }
        }

        if (!empty($metadata)) {
            $metaKey = "{$objectName}.meta";
            $bucket->upload(
                \json_encode($metadata, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR),
                ['name' => $metaKey, 'metadata' => ['contentType' => 'application/json']]
            );
        }

        return "gs://{$this->bucket}/{$objectName}";
    }

    public function download(string $remoteKey, string $localPath): void
    {
        $objectName = $this->prefixed($remoteKey);
        $bucket = $this->client->bucket($this->bucket);
        $object = $bucket->object($objectName);

        $dir = \dirname($localPath);
        if (!\is_dir($dir) && !@\mkdir($dir, 0755, true) && !\is_dir($dir)) {
            throw new \RuntimeException("Failed to create directory: {$dir}");
        }

        $tmpFile = "{$localPath}." . \uniqid('', true) . '.tmp';

        try {
            $object->downloadToFile($tmpFile);
        } catch (\Throwable $e) {
            @\unlink($tmpFile);
            throw new \RuntimeException("Failed to download object \"{$objectName}\" from GCS: {$e->getMessage()}", 0, $e);
        }

        if (!@\rename($tmpFile, $localPath)) {
            @\unlink($tmpFile);
            throw new \RuntimeException("Failed to rename downloaded temp file to: {$localPath}");
        }
    }

    public function delete(string $remoteKey): void
    {
        $objectName = $this->prefixed($remoteKey);
        $bucket = $this->client->bucket($this->bucket);

        if ($bucket->object($objectName)->exists()) {
            $bucket->object($objectName)->delete();
        }

        $metaKey = "{$objectName}.meta";
        if ($bucket->object($metaKey)->exists()) {
            $bucket->object($metaKey)->delete();
        }
    }

    public function list(string $prefix = ''): array
    {
        $fullPrefix = $this->prefixed($prefix);
        $bucket = $this->client->bucket($this->bucket);

        $results = [];

        foreach ($bucket->objects(['prefix' => $fullPrefix]) as $object) {
            $name = $object->name();

            // Skip metadata sidecars from the listing
            if (\str_ends_with($name, '.meta')) {
                continue;
            }

            $info = $object->info();
            $relativeKey = $this->prefix !== ''
                ? \substr($name, \strlen($this->prefix))
                : $name;

            $results[] = [
                'key' => $relativeKey,
                'size' => (int)($info['size'] ?? 0),
                'modified_at' => $info['updated'] ?? '',
            ];
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
