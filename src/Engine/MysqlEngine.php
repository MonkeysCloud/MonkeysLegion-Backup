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
 * MySQL database engine.
 *
 * Dump  : mysqldump with --single-transaction (safe for InnoDB).
 * Restore: mysql (plain SQL piped via stdin).
 *
 * Credentials are injected via the MYSQL_PWD environment variable so they
 * never appear in the argv array, in ps output, or in log messages.
 */
final class MysqlEngine implements EngineInterface
{
    public function __construct(
        private ProcessRunner $runner = new ProcessRunner()
    ) {}

    public function name(): EngineName
    {
        return EngineName::Mysql;
    }

    public function supports(string $feature): bool
    {
        return \in_array($feature, ['compression', 'single-transaction', 'routines', 'triggers'], true);
    }

    // -------------------------------------------------------------------------
    // Dump
    // -------------------------------------------------------------------------

    public function dump(DumpOptions $options): BackupArtifact
    {
        $this->assertBinary('mysqldump');
        $this->assertDatabase($options);

        $tmp  = \sys_get_temp_dir();
        $date = \date('Ymd_His');
        $db   = $options->database;
        $outFile = "{$tmp}/mb_{$db}_{$date}.sql";

        $cmd = $this->buildDumpCmd($options, $outFile);

        $env    = [];
        $redact = [];
        if ($options->password !== null && $options->password !== '') {
            $env['MYSQL_PWD'] = $options->password;
            $redact[]         = $options->password;
        }

        $this->runner->run($cmd, $env, null, $redact);

        return new BackupArtifact(
            localPath: $outFile,
            engine: $this->name()->value,
            database: $options->database
        );
    }

    /**
     * Build the mysqldump argv array (public so unit tests can assert it directly).
     *
     * @return list<string>
     */
    public function buildDumpCmd(DumpOptions $options, string $outFile): array
    {
        $cmd = ['mysqldump'];

        if ($options->host !== null) {
            $host  = $options->host;
            $cmd[] = "--host={$host}";
        }
        if ($options->port !== null) {
            $port  = $options->port;
            $cmd[] = "--port={$port}";
        }
        if ($options->user !== null) {
            $user  = $options->user;
            $cmd[] = "--user={$user}";
        }

        // Roadmap-required defaults
        $cmd[] = '--single-transaction';
        $cmd[] = '--routines';
        $cmd[] = '--triggers';

        foreach ($options->customOptions as $flag) {
            $cmd[] = (string) $flag;
        }

        $cmd[] = "--result-file={$outFile}";

        if ($options->database !== null) {
            $cmd[] = $options->database;
        }

        return $cmd;
    }

    // -------------------------------------------------------------------------
    // Restore
    // -------------------------------------------------------------------------

    public function restore(RestoreOptions $options): void
    {
        $this->assertBinary('mysql');

        $src = $options->sourcePath;
        if (!\is_readable($src)) {
            throw new EngineException("Restore source \"{$src}\" is not readable.");
        }

        $sql = \file_get_contents($src);
        if ($sql === false) {
            throw new EngineException("Cannot read \"{$src}\".");
        }

        $cmd    = $this->buildRestoreCmd($options);
        $env    = [];
        $redact = [];
        if ($options->password !== null && $options->password !== '') {
            $env['MYSQL_PWD'] = $options->password;
            $redact[]         = $options->password;
        }

        $this->runner->run($cmd, $env, $sql, $redact);
    }

    /**
     * Build the mysql restore argv array (public so unit tests can assert it directly).
     *
     * @return list<string>
     */
    public function buildRestoreCmd(RestoreOptions $options): array
    {
        $cmd = ['mysql'];

        if ($options->host !== null) {
            $host  = $options->host;
            $cmd[] = "--host={$host}";
        }
        if ($options->port !== null) {
            $port  = $options->port;
            $cmd[] = "--port={$port}";
        }
        if ($options->user !== null) {
            $user  = $options->user;
            $cmd[] = "--user={$user}";
        }
        if ($options->database !== null) {
            $cmd[] = $options->database;
        }

        return $cmd;
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

    private function assertDatabase(DumpOptions $options): void
    {
        if ($options->database === null || $options->database === '') {
            throw new EngineException('MySQL dump requires a database name.');
        }
    }
}
