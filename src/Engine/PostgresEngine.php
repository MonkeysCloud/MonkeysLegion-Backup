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
 * PostgreSQL database engine.
 *
 * Dump  : pg_dump (supports plain SQL and custom -Fc format).
 * Restore: psql for plain SQL; pg_restore for custom -Fc format.
 *
 * Credentials are passed via environment variables (PGPASSWORD) so they
 * never appear in the argv array, in ps output, or in log messages.
 */
final class PostgresEngine implements EngineInterface
{
    public function __construct(
        private ProcessRunner $runner = new ProcessRunner()
    ) {}

    public function name(): string
    {
        return EngineName::Postgres->value;
    }

    public function supports(string $feature): bool
    {
        return \in_array($feature, ['compression', 'format-custom', 'format-plain'], true);
    }

    // -------------------------------------------------------------------------
    // Dump
    // -------------------------------------------------------------------------

    public function dump(DumpOptions $options): BackupArtifact
    {
        $this->assertBinary('pg_dump');
        $this->assertDatabase($options);

        $tmp  = \sys_get_temp_dir();
        $date = \date('Ymd_His');
        $db   = $options->database;

        // Detect format
        $format = $this->determineFormat($options->customOptions);
        $ext = $format === 'custom' ? 'dump' : 'sql';
        $outFile = "{$tmp}/mb_{$db}_{$date}.{$ext}";

        $cmd = $this->buildDumpCmd($options, $outFile, $format);

        $env = [];
        $redact = [];
        if ($options->password !== null && $options->password !== '') {
            $env['PGPASSWORD'] = $options->password;
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
     * @param array<array-key, mixed> $customOptions
     */
    private function determineFormat(array $customOptions): string
    {
        // Check associative format key
        if (isset($customOptions['format'])) {
            $f = \strval($customOptions['format']);
            if ($f === 'custom' || $f === 'c') {
                return 'custom';
            }
        }

        // Check sequential customOptions list
        foreach ($customOptions as $opt) {
            if (\is_string($opt)) {
                if ($opt === '-Fc' || $opt === '--format=custom' || $opt === '--format=c') {
                    return 'custom';
                }
            }
        }

        return 'plain';
    }

    /**
     * Build the pg_dump argv array.
     *
     * @return list<string>
     */
    public function buildDumpCmd(DumpOptions $options, string $outFile, ?string $format = null): array
    {
        $cmd = ['pg_dump'];

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

        $cmd[] = '--no-password';

        $format = $format ?? $this->determineFormat($options->customOptions);
        if ($format === 'custom') {
            $cmd[] = '--format=custom';
        } else {
            $cmd[] = '--format=plain';
        }

        $cmd[] = "--file={$outFile}";

        // Add other custom options if they are strings
        foreach ($options->customOptions as $k => $v) {
            if (\is_int($k) && \is_string($v)) {
                // If it is a format flag, ignore since we handle it explicitly
                if ($v === '-Fc' || $v === '--format=custom' || $v === '--format=c' || $v === '-Fp' || $v === '--format=plain' || $v === '--format=p') {
                    continue;
                }
                $cmd[] = $v;
            }
        }

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
        $src = $options->sourcePath;
        if (!\is_readable($src)) {
            throw new EngineException("Restore source \"{$src}\" is not readable.");
        }

        $isCustom = $this->isCustomFormat($src);
        $bin = $isCustom ? 'pg_restore' : 'psql';
        $this->assertBinary($bin);

        $cmd = $this->buildRestoreCmd($options, $isCustom);
        $env = [];
        $redact = [];
        if ($options->password !== null && $options->password !== '') {
            $env['PGPASSWORD'] = $options->password;
            $redact[] = $options->password;
        }

        if ($isCustom) {
            // pg_restore reads file directly from command line or stdin
            // We pass it directly in argv, so stdin is null
            $this->runner->run($cmd, $env, null, $redact);
        } else {
            // psql reads SQL from stdin
            $sql = \file_get_contents($src);
            if ($sql === false) {
                throw new EngineException("Cannot read \"{$src}\".");
            }
            $this->runner->run($cmd, $env, $sql, $redact);
        }
    }

    /**
     * Build the restore argv array.
     *
     * @return list<string>
     */
    public function buildRestoreCmd(RestoreOptions $options, ?bool $isCustom = null): array
    {
        if ($isCustom === null) {
            $isCustom = $this->isCustomFormat($options->sourcePath);
        }

        $cmd = [$isCustom ? 'pg_restore' : 'psql'];

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

        $cmd[] = '--no-password';

        if ($isCustom) {
            // pg_restore needs --clean or --dbname
            if ($options->database !== null) {
                $db = $options->database;
                $cmd[] = "--dbname={$db}";
            }
            // For custom restore options passed as list
            foreach ($options->customOptions as $k => $v) {
                if (\is_int($k) && \is_string($v)) {
                    $cmd[] = $v;
                }
            }
            $cmd[] = $options->sourcePath;
        } else {
            if ($options->database !== null) {
                $db = $options->database;
                $cmd[] = "--dbname={$db}";
            }
            foreach ($options->customOptions as $k => $v) {
                if (\is_int($k) && \is_string($v)) {
                    $cmd[] = $v;
                }
            }
        }

        return $cmd;
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function isCustomFormat(string $filePath): bool
    {
        if (!\is_file($filePath) || !\is_readable($filePath)) {
            return false;
        }
        $handle = \fopen($filePath, 'rb');
        if ($handle === false) {
            return false;
        }
        $header = \fread($handle, 5);
        \fclose($handle);
        return $header === 'PGDMP';
    }

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
            throw new EngineException('PostgreSQL dump requires a database name.');
        }
    }
}
