<?php

declare(strict_types=1);

namespace MonkeysLegion\Backup\Runner;

use MonkeysLegion\Backup\Contract\CompressorInterface;
use MonkeysLegion\Backup\Contract\LoggerInterface;
use MonkeysLegion\Backup\Contract\StorageAdapterInterface;
use MonkeysLegion\Backup\Engine\EngineRegistry;
use MonkeysLegion\Backup\Exception\BackupException;
use MonkeysLegion\Backup\ValueObject\BackupMetadata;
use MonkeysLegion\Backup\ValueObject\BackupResult;
use MonkeysLegion\Backup\ValueObject\DumpOptions;
use DateTimeImmutable;

/**
 * Coordinates the full database backup pipeline:
 * Dump -> Compress (optional) -> Checksum -> Metadata sidecar -> Storage upload.
 */
final class BackupRunner
{
    public function __construct(
        private EngineRegistry $engineRegistry,
        private StorageAdapterInterface $storage,
        private CompressorInterface $compressor,
        private ?LoggerInterface $logger = null
    ) {}

    /**
     * Run the backup orchestration.
     *
     * @throws BackupException|\Throwable
     */
    public function run(DumpOptions $options, string $remoteKey): BackupResult
    {
        $startTime = \microtime(true);
        $this->log("Starting backup process for database: {$options->database}", [
            'engine' => $options->engine,
            'database' => $options->database,
            'remoteKey' => $remoteKey,
        ]);

        $engine = $this->engineRegistry->get($options->engine);

        $this->log("Dumping database...");
        $artifact = $engine->dump($options);
        $localPath = $artifact->localPath;

        try {
            $originalSize = \filesize($localPath);
            if ($originalSize === false) {
                throw new BackupException("Failed to read size of dump artifact: {$localPath}");
            }

            $compressed = false;
            $uploadPath = $localPath;

            if ($options->compress) {
                if ($engine->supports('compression')) {
                    $this->log("Compressing dump artifact...");
                    $ext = $this->compressor->extension();
                    $compressedPath = "{$localPath}.{$ext}";
                    $this->compressor->compress($localPath, $compressedPath);

                    @\unlink($localPath);
                    $uploadPath = $compressedPath;
                    $compressed = true;
                } else {
                    $this->log("Engine does not support compression. Skipping compression step.");
                }
            }

            $finalSize = \filesize($uploadPath);
            if ($finalSize === false) {
                throw new BackupException("Failed to read size of final backup file: {$uploadPath}");
            }

            $this->log("Computing checksum...");
            $checksum = \hash_file('sha256', $uploadPath);
            if ($checksum === false) {
                throw new BackupException("Failed to calculate SHA-256 checksum for: {$uploadPath}");
            }

            $metadata = new BackupMetadata(
                engine: $options->engine,
                version: '1.0.0',
                createdAt: new DateTimeImmutable(),
                checksum: $checksum,
                compressed: $compressed,
                originalSize: (int)$originalSize,
                compressedSize: (int)$finalSize
            );

            $this->log("Uploading backup to storage...");
            $this->storage->upload($uploadPath, $remoteKey, $metadata->toArray());

            @\unlink($uploadPath);

            $duration = \microtime(true) - $startTime;
            $this->log("Backup process completed successfully in {$duration}s.");

            return new BackupResult(
                remoteKey: $remoteKey,
                sizeBytes: $finalSize,
                checksum: $checksum,
                duration: $duration
            );
        } catch (\Throwable $e) {
            if (\is_file($localPath)) {
                @\unlink($localPath);
            }
            if (isset($uploadPath) && \is_file($uploadPath)) {
                @\unlink($uploadPath);
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
