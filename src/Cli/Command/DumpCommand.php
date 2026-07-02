<?php

declare(strict_types=1);

namespace MonkeysLegion\Backup\Cli\Command;

use MonkeysLegion\Cli\Console\Attributes\Command as CliCommand;
use MonkeysLegion\Backup\ValueObject\DumpOptions;
use MonkeysLegion\Backup\Runner\BackupRunner;
use MonkeysLegion\Backup\Contract\LoggerInterface;

#[CliCommand('backup:dump', 'Dump a database and upload it to the storage backend')]
final class DumpCommand extends BaseCommand
{
    protected function handle(): int
    {
        $engine = $this->getSafeOption('engine');
        if (!$engine || !\is_string($engine)) {
            $this->error("Error: --engine option is required.");
            return self::FAILURE;
        }

        $database = $this->getDbOption($engine, 'database');
        if (!$database || !\is_string($database)) {
            $this->error("Error: --database option (or environment variable) is required.");
            return self::FAILURE;
        }

        $host     = $this->getDbOption($engine, 'host');
        $portVal  = $this->getDbOption($engine, 'port');
        $port     = $portVal !== null ? (int) $portVal : null;
        $user     = $this->getDbOption($engine, 'user');
        $password = $this->getDbOption($engine, 'password');

        $compress = \filter_var($this->getSafeOption('compress', false), FILTER_VALIDATE_BOOLEAN);

        $key = $this->getSafeOption('key');
        if (!$key || !\is_string($key)) {
            $ext     = $compress ? 'sql.gz' : 'sql';
            $dateStr = \date('Ymd_His');
            $key     = "backups/{$database}_{$dateStr}.{$ext}";
        }

        $customRaw     = $this->getSafeOption('custom-options', '');
        $customOptions = $customRaw ? \explode(',', (string) $customRaw) : [];

        $dumpOpts = new DumpOptions(
            engine: $engine,
            host: $host,
            port: $port,
            user: $user,
            password: $password,
            database: $database,
            compress: $compress,
            customOptions: $customOptions
        );

        $hostLabel     = $host ?: 'default';
        $portLabel     = (string) ($port ?: 'default');
        $userLabel     = $user ?: 'default';
        $compressLabel = $compress ? 'Yes' : 'No';

        $this->info("Backup Plan:");
        $this->info("  Engine:     {$engine}");
        $this->info("  Database:   {$database}");
        $this->info("  Host:       {$hostLabel}");
        $this->info("  Port:       {$portLabel}");
        $this->info("  User:       {$userLabel}");
        $this->info("  Compress:   {$compressLabel}");
        $this->info("  Target Key: {$key}");

        if ($this->isDryRun()) {
            $this->warn("Dry-run mode active. No database backup was run.");
            return self::SUCCESS;
        }

        try {
            $logger = $this->resolveLogger();
            if ($logger !== null) {
                $this->container()->set(LoggerInterface::class, $logger);
            }

            /** @var BackupRunner $runner */
            $runner = $this->container()->make(BackupRunner::class);
        } catch (\Throwable $e) {
            $this->error("Required services are not registered: {$e->getMessage()}");
            return self::FAILURE;
        }

        try {
            $this->info("Running backup...");
            $logger?->log("Running backup...");

            $result = $runner->run($dumpOpts, $key);

            $resultSize = (string) $result->sizeBytes;
            $resultDur  = \number_format($result->duration, 2);

            $this->info("Backup successfully completed!");
            $this->info("  Remote Key: {$result->remoteKey}");
            $this->info("  Size:       {$resultSize} bytes");
            $this->info("  Checksum:   {$result->checksum}");
            $this->info("  Duration:   {$resultDur}s");

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error("Backup failed: {$e->getMessage()}");
            return self::FAILURE;
        }
    }
}
