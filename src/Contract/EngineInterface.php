<?php

declare(strict_types=1);

namespace MonkeysLegion\Backup\Contract;

use MonkeysLegion\Backup\ValueObject\DumpOptions;
use MonkeysLegion\Backup\ValueObject\RestoreOptions;
use MonkeysLegion\Backup\ValueObject\BackupArtifact;

interface EngineInterface
{
    /**
     * Return the engine's unique identifier string (e.g. 'mysql', 'postgres', 'cassandra').
     *
     * Built-in engines return the value of the matching {@see \MonkeysLegion\Backup\Engine\EngineName} case.
     * Custom engines may return any non-empty lowercase string.
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
