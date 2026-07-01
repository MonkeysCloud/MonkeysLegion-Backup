<?php

declare(strict_types=1);

namespace MonkeysLegion\Backup\Contract;

interface StorageAdapterInterface
{
    /**
     * Upload a local backup artifact file to remote storage.
     *
     * @param string $localPath Path to the local file to upload.
     * @param string $remoteKey The target key/path in remote storage.
     * @param array<string, mixed> $metadata Optional metadata sidecar array.
     * @return string The URI or reference path of the uploaded object.
     */
    public function upload(string $localPath, string $remoteKey, array $metadata = []): string;

    /**
     * Download a file from remote storage to a local path.
     *
     * @param string $remoteKey The key/path in remote storage.
     * @param string $localPath Path where the downloaded file should be saved.
     */
    public function download(string $remoteKey, string $localPath): void;

    /**
     * Delete a file from remote storage.
     *
     * @param string $remoteKey The key/path in remote storage to delete.
     */
    public function delete(string $remoteKey): void;

    /**
     * List files in remote storage matching an optional prefix.
     *
     * @param string $prefix Optional key prefix filter.
     * @return list<array{key: string, size: int, modified_at: string}> List of matching items with details.
     */
    public function list(string $prefix = ''): array;
}
