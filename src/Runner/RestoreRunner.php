<?php

declare(strict_types=1);

namespace MonkeysLegion\Backup\Runner;

use MonkeysLegion\Backup\Contract\CompressorInterface;
use MonkeysLegion\Backup\Contract\LoggerInterface;
use MonkeysLegion\Backup\Contract\StorageAdapterInterface;
use MonkeysLegion\Backup\Engine\EngineRegistry;
use MonkeysLegion\Backup\Exception\BackupException;
use MonkeysLegion\Backup\ValueObject\BackupMetadata;
use MonkeysLegion\Backup\ValueObject\RestoreOptions;

/**
 * Coordinates the full database restoration pipeline:
 * Download Metadata sidecar -> Download Backup -> Verify Checksum -> Decompress (if needed) -> Engine Restore.
 */
final class RestoreRunner
{
    public function __construct(
        private EngineRegistry $engineRegistry,
        private StorageAdapterInterface $storage,
        private CompressorInterface $compressor,
        private ?LoggerInterface $logger = null
    ) {}

    /**
     * Run the restore orchestration.
     *
     * @throws BackupException|\Throwable
     */
    public function run(RestoreOptions $options): void
    {
        $startTime = \microtime(true);
        $this->log("Starting restore process for database: {$options->database}", [
            'engine' => $options->engine,
            'database' => $options->database,
            'sourcePath' => $options->sourcePath,
        ]);

        $remoteKey = $options->sourcePath;
        $engine = $this->engineRegistry->get($options->engine);

        $tmpDir = \sys_get_temp_dir();
        $uniq = \uniqid('', true);
        $localMetaPath = "{$tmpDir}/mb_restore_meta_{$uniq}.meta";
        $localBackupPath = "{$tmpDir}/mb_restore_backup_{$uniq}";

        try {
            $this->log("Downloading metadata sidecar...");
            $metaKey = "{$remoteKey}.meta";

            try {
                $this->storage->download($metaKey, $localMetaPath);
            } catch (\Throwable $e) {
                throw new BackupException("Failed to download metadata for backup: {$remoteKey}. Ensure the backup exists and has a metadata file.", 0, [], $e);
            }

            $metaJson = \file_get_contents($localMetaPath);
            if ($metaJson === false) {
                throw new BackupException("Failed to read downloaded metadata file: {$localMetaPath}");
            }

            $metadata = BackupMetadata::fromJson($metaJson);

            $this->log("Downloading backup file...");
            $this->storage->download($remoteKey, $localBackupPath);

            $this->log("Verifying SHA-256 checksum...");
            $actualChecksum = \hash_file('sha256', $localBackupPath);
            if ($actualChecksum !== $metadata->checksum) {
                throw new BackupException("Checksum verification failed. Expected: {$metadata->checksum}, Got: {$actualChecksum}");
            }

            $restorePath = $localBackupPath;

            if ($metadata->compressed) {
                $this->log("Decompressing backup...");
                $decompressedPath = "{$localBackupPath}.decompressed";
                $this->compressor->decompress($localBackupPath, $decompressedPath);

                @\unlink($localBackupPath);
                $restorePath = $decompressedPath;
            }

            $this->log("Restoring database...");
            $engineRestoreOptions = new RestoreOptions(
                engine: $options->engine,
                sourcePath: $restorePath,
                host: $options->host,
                port: $options->port,
                user: $options->user,
                password: $options->password,
                database: $options->database,
                customOptions: $options->customOptions
            );

            $engine->restore($engineRestoreOptions);

            @\unlink($restorePath);
            @\unlink($localMetaPath);

            $duration = \microtime(true) - $startTime;
            $this->log("Restore process completed successfully in {$duration}s.");

        } catch (\Throwable $e) {
            if (\is_file($localMetaPath)) {
                @\unlink($localMetaPath);
            }
            if (\is_file($localBackupPath)) {
                @\unlink($localBackupPath);
            }
            if (isset($restorePath) && \is_file($restorePath)) {
                @\unlink($restorePath);
            }
            throw $e;
        }
    }

    /**
     * Safe internal logger helper that masks any passwords.
     *
     * @param array<string, mixed> $context
     */
    private function log(string $message, array $context = []): void
    {
        if ($this->logger !== null) {
            $cleanContext = [];
            foreach ($context as $key => $val) {
                if (\is_string($val) && (\str_contains($key, 'password') || \str_contains($key, 'pwd') || \str_contains($key, 'secret'))) {
                    $cleanContext[$key] = '***';
                } else {
                    $cleanContext[$key] = $val;
                }
            }
            $this->logger->log($message, $cleanContext);
        }
    }
}
