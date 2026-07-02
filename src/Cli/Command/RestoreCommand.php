<?php

declare(strict_types=1);

namespace MonkeysLegion\Backup\Cli\Command;

use MonkeysLegion\Cli\Console\Attributes\Command as CliCommand;
use MonkeysLegion\Backup\ValueObject\RestoreOptions;
use MonkeysLegion\Backup\Runner\RestoreRunner;
use MonkeysLegion\Backup\Contract\LoggerInterface;

#[CliCommand('backup:restore', 'Restore a database from a backup stored in the storage backend')]
final class RestoreCommand extends BaseCommand
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

        $key = $this->getSafeOption('key');
        if (!$key || !\is_string($key)) {
            $this->error("Error: --key option (backup key) is required.");
            return self::FAILURE;
        }

        $host     = $this->getDbOption($engine, 'host');
        $portVal  = $this->getDbOption($engine, 'port');
        $port     = $portVal !== null ? (int) $portVal : null;
        $user     = $this->getDbOption($engine, 'user');
        $password = $this->getDbOption($engine, 'password');

        $customRaw     = $this->getSafeOption('custom-options', '');
        $customOptions = $customRaw ? \explode(',', (string) $customRaw) : [];

        $restoreOpts = new RestoreOptions(
            engine: $engine,
            sourcePath: $key,
            host: $host,
            port: $port,
            user: $user,
            password: $password,
            database: $database,
            customOptions: $customOptions
        );

        $hostLabel = $host ?: 'default';
        $portLabel = (string) ($port ?: 'default');
        $userLabel = $user ?: 'default';

        $this->info("Restore Plan:");
        $this->info("  Engine:      {$engine}");
        $this->info("  Database:    {$database}");
        $this->info("  Host:        {$hostLabel}");
        $this->info("  Port:        {$portLabel}");
        $this->info("  User:        {$userLabel}");
        $this->info("  Source Key:  {$key}");

        if ($this->isDryRun()) {
            $this->warn("Dry-run mode active. No database restore was run.");
            return self::SUCCESS;
        }

        try {
            $logger = $this->resolveLogger();
            if ($logger !== null) {
                $this->container()->set(LoggerInterface::class, $logger);
            }

            /** @var RestoreRunner $runner */
            $runner = $this->container()->make(RestoreRunner::class);
        } catch (\Throwable $e) {
            $this->error("Required services are not registered: {$e->getMessage()}");
            return self::FAILURE;
        }

        try {
            $this->info("Running restore...");
            $logger?->log("Running restore...");

            $runner->run($restoreOpts);

            $this->info("Restore successfully completed!");
            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error("Restore failed: {$e->getMessage()}");
            return self::FAILURE;
        }
    }
}
