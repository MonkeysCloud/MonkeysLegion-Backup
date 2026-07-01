<?php

declare(strict_types=1);

namespace MonkeysLegion\Backup\ValueObject;

/**
 * Immutable value object holding the outcome of a backup run.
 */
readonly class BackupResult
{
    public function __construct(
        public string $remoteKey,
        public int $sizeBytes,
        public string $checksum,
        public float $duration
    ) {}

    /**
     * Get the remote storage key/path of the uploaded backup.
     */
    public function remoteKey(): string
    {
        return $this->remoteKey;
    }

    /**
     * Get the size of the uploaded backup file in bytes.
     */
    public function sizeBytes(): int
    {
        return $this->sizeBytes;
    }

    /**
     * Get the SHA-256 checksum of the uploaded backup.
     */
    public function checksum(): string
    {
        return $this->checksum;
    }

    /**
     * Get the execution duration of the backup process in seconds.
     */
    public function duration(): float
    {
        return $this->duration;
    }
}
