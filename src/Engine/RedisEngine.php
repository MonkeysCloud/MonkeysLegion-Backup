<?php

declare(strict_types=1);

namespace MonkeysLegion\Backup\Engine;

use MonkeysLegion\Backup\Contract\EngineInterface;
use MonkeysLegion\Backup\Exception\EngineException;
use MonkeysLegion\Backup\Process\ProcessRunner;
use MonkeysLegion\Backup\ValueObject\BackupArtifact;
use MonkeysLegion\Backup\ValueObject\DumpOptions;
use MonkeysLegion\Backup\ValueObject\RestoreOptions;

/**
 * Redis database engine.
 *
 * Dump  : redis-cli --rdb
 * Restore: File copy of RDB file to target active directory.
 *
 * CAVEATS:
 * Redis RDB restore requires replacing the server's dump.rdb file and restarting the
 * Redis server, or using a client/tool that imports RDB.
 *
 * Credentials are passed via REDISCLI_AUTH environment variable for process safety.
 */
final class RedisEngine implements EngineInterface
{
    public function __construct(
        private ProcessRunner $runner = new ProcessRunner()
    ) {}

    public function name(): string
    {
        return EngineName::Redis->value;
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
        $this->assertBinary('redis-cli');

        $tmp  = \sys_get_temp_dir();
        $date = \date('Ymd_His');
        $db   = $options->database ?? 'default';
        $outFile = "{$tmp}/mb_{$db}_{$date}.rdb";

        $cmd = $this->buildDumpCmd($options, $outFile);

        $env = [];
        $redact = [];
        if ($options->password !== null && $options->password !== '') {
            $env['REDISCLI_AUTH'] = $options->password;
            $redact[] = $options->password;
        }

        $this->runner->run($cmd, $env, null, $redact);

        return new BackupArtifact(
            localPath: $outFile,
            engine: $this->name(),
            database: $db
        );
    }

    /**
     * Build the redis-cli dump argv array.
     *
     * @return list<string>
     */
    public function buildDumpCmd(DumpOptions $options, string $outFile): array
    {
        $cmd = ['redis-cli'];

        if ($options->host !== null) {
            $host = $options->host;
            $cmd[] = '-h';
            $cmd[] = $host;
        }
        if ($options->port !== null) {
            $port = $options->port;
            $cmd[] = '-p';
            $cmd[] = (string)$port;
        }
        if ($options->user !== null && $options->user !== '') {
            $user = $options->user;
            $cmd[] = '--user';
            $cmd[] = $user;
        }

        $cmd[] = '--no-auth-warning';
        $cmd[] = '--rdb';
        $cmd[] = $outFile;

        foreach ($options->customOptions as $flag) {
            $cmd[] = (string)$flag;
        }

        return $cmd;
    }

    // -------------------------------------------------------------------------
    // Restore
    // -------------------------------------------------------------------------

    /**
     * Restore a Redis RDB backup by replacing the active dump.rdb file.
     *
     * CAVEAT: Redis restore is not a simple query command. The standard way to restore
     * an RDB backup is to replace the server's active "dump.rdb" file and restart the
     * Redis service.
     *
     * If the destination path ($options->database) is configured to a local path where
     * the target "dump.rdb" is located, this engine will copy the backup file over it.
     * Note that the Redis server should be stopped before replacing the file, and
     * restarted afterwards.
     */
    public function restore(RestoreOptions $options): void
    {
        $db = $options->database;
        if ($db === null || $db === '') {
            throw new EngineException('Redis restore requires specifying the path to the target dump.rdb file in the database option.');
        }

        $src = $options->sourcePath;
        if (!\is_readable($src)) {
            throw new EngineException("Restore source \"{$src}\" is not readable.");
        }

        $dir = \dirname($db);
        if (!\is_dir($dir)) {
            if (!\mkdir($dir, 0755, true) && !\is_dir($dir)) {
                throw new EngineException("Failed to create directory \"{$dir}\" for Redis RDB restore.");
            }
        }

        if (!\copy($src, $db)) {
            throw new EngineException("Failed to copy RDB backup from \"{$src}\" to \"{$db}\".");
        }
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function assertBinary(string $bin): void
    {
        \exec("command -v {$bin}", $out, $code);
        if ($code !== 0) {
            throw new EngineException("Required binary \"{$bin}\" not found on PATH.");
        }
    }
}
