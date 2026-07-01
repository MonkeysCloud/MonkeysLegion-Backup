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
 * MongoDB database engine.
 *
 * Dump  : mongodump --archive
 * Restore: mongorestore --archive
 *
 * Credentials are passed securely via the command argv and redacted by the ProcessRunner.
 */
final class MongodbEngine implements EngineInterface
{
    public function __construct(
        private ProcessRunner $runner = new ProcessRunner()
    ) {}

    public function name(): string
    {
        return EngineName::MongoDB->value;
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
        $this->assertDatabase($options);
        $this->assertBinary('mongodump');

        $tmp  = \sys_get_temp_dir();
        $date = \date('Ymd_His');
        $db   = $options->database;
        $outFile = "{$tmp}/mb_{$db}_{$date}.archive";

        $cmd = $this->buildDumpCmd($options, $outFile);

        $env = [];
        $redact = [];
        if ($options->password !== null && $options->password !== '') {
            $redact[] = $options->password;
        }

        $this->runner->run($cmd, $env, null, $redact);

        return new BackupArtifact(
            localPath: $outFile,
            engine: $this->name(),
            database: $options->database
        );
    }

    /**
     * Build the mongodump argv array.
     *
     * @return list<string>
     */
    public function buildDumpCmd(DumpOptions $options, string $outFile): array
    {
        $cmd = ['mongodump', "--archive={$outFile}"];

        if ($options->database !== null) {
            $db = $options->database;
            $cmd[] = "--db={$db}";
        }

        if ($options->host !== null) {
            $host = $options->host;
            $cmd[] = "--host={$host}";
        }
        if ($options->port !== null) {
            $port = $options->port;
            $cmd[] = "--port={$port}";
        }
        if ($options->user !== null) {
            $user = $options->user;
            $cmd[] = "--username={$user}";
        }
        if ($options->password !== null && $options->password !== '') {
            $pwd = $options->password;
            $cmd[] = "--password={$pwd}";
        }

        foreach ($options->customOptions as $flag) {
            $cmd[] = (string)$flag;
        }

        return $cmd;
    }

    // -------------------------------------------------------------------------
    // Restore
    // -------------------------------------------------------------------------

    public function restore(RestoreOptions $options): void
    {
        $src = $options->sourcePath;
        if (!\is_readable($src)) {
            throw new EngineException("Restore source \"{$src}\" is not readable.");
        }

        $this->assertBinary('mongorestore');

        $cmd = $this->buildRestoreCmd($options);

        $env = [];
        $redact = [];
        if ($options->password !== null && $options->password !== '') {
            $redact[] = $options->password;
        }

        $this->runner->run($cmd, $env, null, $redact);
    }

    /**
     * Build the mongorestore argv array.
     *
     * @return list<string>
     */
    public function buildRestoreCmd(RestoreOptions $options): array
    {
        $cmd = ['mongorestore', "--archive={$options->sourcePath}"];

        if ($options->database !== null) {
            $db = $options->database;
            $cmd[] = "--db={$db}";
        }

        if ($options->host !== null) {
            $host = $options->host;
            $cmd[] = "--host={$host}";
        }
        if ($options->port !== null) {
            $port = $options->port;
            $cmd[] = "--port={$port}";
        }
        if ($options->user !== null) {
            $user = $options->user;
            $cmd[] = "--username={$user}";
        }
        if ($options->password !== null && $options->password !== '') {
            $pwd = $options->password;
            $cmd[] = "--password={$pwd}";
        }

        foreach ($options->customOptions as $flag) {
            $cmd[] = (string)$flag;
        }

        return $cmd;
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function assertBinary(string $bin): void
    {
        $proc = @\proc_open(['which', $bin], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
        $code = \is_resource($proc) ? \proc_close($proc) : 1;
        if ($code !== 0) {
            throw new EngineException("Required binary \"{$bin}\" not found on PATH.");
        }
    }

    private function assertDatabase(DumpOptions $options): void
    {
        if ($options->database === null || $options->database === '') {
            throw new EngineException('MongoDB dump requires a database name.');
        }
    }
}
