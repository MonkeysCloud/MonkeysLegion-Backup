<?php

declare(strict_types=1);

namespace MonkeysLegion\Backup\Engine;

use MonkeysLegion\Backup\Contract\EngineInterface;
use MonkeysLegion\Backup\Exception\EngineException;
use MonkeysLegion\Backup\ValueObject\BackupArtifact;
use MonkeysLegion\Backup\ValueObject\DumpOptions;
use MonkeysLegion\Backup\ValueObject\RestoreOptions;

/**
 * SQLite database engine.
 *
 * Dump  : File copy of active .db / .sqlite file, or SQLite3/PDO backup api for in-memory databases.
 * Restore: File copy of RDB file to target active directory, or SQLite3/PDO backup api for in-memory databases.
 */
final class SqliteEngine implements EngineInterface
{
    public function name(): EngineName
    {
        return EngineName::SQLite;
    }

    public function supports(string $feature): bool
    {
        return \in_array($feature, ['compression'], true);
    }

    // -------------------------------------------------------------------------
    // Dump
    // -------------------------------------------------------------------------

    public function dump(DumpOptions $options): BackupArtifact
    {
        $db = $options->database;
        if ($db === null || $db === '') {
            throw new EngineException('SQLite dump requires a database path or \':memory:\'.');
        }

        $tmp  = \sys_get_temp_dir();
        $date = \date('Ymd_His');
        // Sanitise DB name for temp filename
        $safeDb = \preg_replace('/[^a-zA-Z0-9_-]/', '_', $db);
        $outFile = "{$tmp}/mb_{$safeDb}_{$date}.db";

        if ($db === ':memory:' || \str_starts_with($db, 'file:')) {
            try {
                $sourceConn = $options->customOptions['connection'] ?? null;
                if ($sourceConn instanceof \SQLite3) {
                    $dest = new \SQLite3($outFile);
                    $sourceConn->backup($dest);
                    $dest->close();
                } elseif ($sourceConn instanceof \PDO) {
                    $sourceConn->exec("VACUUM INTO '{$outFile}'");
                } else {
                    // Create a new empty database
                    $source = new \SQLite3($db);
                    $dest = new \SQLite3($outFile);
                    $source->backup($dest);
                    $dest->close();
                    $source->close();
                }
            } catch (\Throwable $e) {
                throw new EngineException("Failed to dump SQLite in-memory database: {$e->getMessage()}", 0, [], $e);
            }
        } else {
            if (!\is_file($db)) {
                throw new EngineException("SQLite database file not found at \"{$db}\".");
            }
            if (!\is_readable($db)) {
                throw new EngineException("SQLite database file at \"{$db}\" is not readable.");
            }

            if (!\copy($db, $outFile)) {
                throw new EngineException("Failed to copy SQLite database file from \"{$db}\" to \"{$outFile}\".");
            }
        }

        return new BackupArtifact(
            localPath: $outFile,
            engine: $this->name()->value,
            database: $db
        );
    }

    // -------------------------------------------------------------------------
    // Restore
    // -------------------------------------------------------------------------

    public function restore(RestoreOptions $options): void
    {
        $db = $options->database;
        if ($db === null || $db === '') {
            throw new EngineException('SQLite restore requires a destination database path or \':memory:\'.');
        }

        $src = $options->sourcePath;
        if (!\is_file($src) || !\is_readable($src)) {
            throw new EngineException("Restore source \"{$src}\" is not readable.");
        }

        if ($db === ':memory:' || \str_starts_with($db, 'file:')) {
            try {
                $destConn = $options->customOptions['connection'] ?? null;
                if ($destConn instanceof \SQLite3) {
                    $source = new \SQLite3($src);
                    $source->backup($destConn);
                    $source->close();
                } elseif ($destConn instanceof \PDO) {
                    $destConn->exec("ATTACH DATABASE '{$src}' AS backup_src;");
                    $stmt = $destConn->query("SELECT name, sql FROM backup_src.sqlite_schema WHERE type='table' AND name NOT LIKE 'sqlite_%';");
                    if ($stmt !== false) {
                        $tables = $stmt->fetchAll(\PDO::FETCH_ASSOC);
                        $stmt->closeCursor();
                        foreach ($tables as $row) {
                            $tableName = $row['name'];
                            $createSql = $row['sql'];
                            $destConn->exec("DROP TABLE IF EXISTS main.{$tableName};");
                            $destConn->exec($createSql);
                            $destConn->exec("INSERT INTO main.{$tableName} SELECT * FROM backup_src.{$tableName};");
                        }
                    }
                    $destConn->exec("DETACH DATABASE backup_src;");
                } else {
                    $dest = new \SQLite3($db);
                    $source = new \SQLite3($src);
                    $source->backup($dest);
                    $source->close();
                    $dest->close();
                }
            } catch (\Throwable $e) {
                throw new EngineException("Failed to restore SQLite in-memory database: {$e->getMessage()}", 0, [], $e);
            }
        } else {
            $dir = \dirname($db);
            if (!\is_dir($dir)) {
                if (!\mkdir($dir, 0755, true) && !\is_dir($dir)) {
                    throw new EngineException("Failed to create directory \"{$dir}\" for SQLite database.");
                }
            }

            if (!\copy($src, $db)) {
                throw new EngineException("Failed to restore SQLite database by copying from \"{$src}\" to \"{$db}\".");
            }
        }
    }
}
