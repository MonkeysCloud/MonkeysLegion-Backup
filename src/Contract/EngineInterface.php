<?php

declare(strict_types=1);

namespace MonkeysLegion\Backup\Contract;

use MonkeysLegion\Backup\ValueObject\DumpOptions;
use MonkeysLegion\Backup\ValueObject\RestoreOptions;
use MonkeysLegion\Backup\ValueObject\BackupArtifact;

interface EngineInterface
{
    /**
     * Get the name of the database engine (e.g., 'mysql', 'postgres', 'mongodb', etc.).
     */
    public function name(): string;

    /**
     * Dump the database using engine-specific configuration and return the local artifact.
     */
    public function dump(DumpOptions $options): BackupArtifact;

    /**
     * Restore the database from a local backup file.
     */
    public function restore(RestoreOptions $options): void;

    /**
     * Check if the engine supports a specific option or capability.
     */
    public function supports(string $feature): bool;
}
