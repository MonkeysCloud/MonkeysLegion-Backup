<?php

declare(strict_types=1);

namespace MonkeysLegion\Backup\ValueObject;

use DateTimeImmutable;

/**
 * Immutable representation of a local backup dump file before compression or upload.
 */
readonly class BackupArtifact
{
    public function __construct(
        public string $localPath,
        public string $engine,
        public ?string $database = null,
        public DateTimeImmutable $createdAt = new DateTimeImmutable()
    ) {}
}
