<?php

declare(strict_types=1);

namespace MonkeysLegion\Backup\Cli\Command;

use MonkeysLegion\Cli\Console\Attributes\Command as CliCommand;
use MonkeysLegion\Backup\Contract\StorageAdapterInterface;

#[CliCommand('list', 'List all database backups stored in the storage backend')]
final class ListCommand extends BaseCommand
{
    protected function handle(): int
    {
        try {
            $storage = $this->container()->get(StorageAdapterInterface::class);
        } catch (\Throwable) {
            $this->error("StorageAdapterInterface is not registered in the container.");
            return self::FAILURE;
        }

        $prefix    = $this->getSafeOption('prefix', '');
        $prefLabel = $prefix ?: 'none';
        $this->info("Listing backups from storage (prefix: {$prefLabel})...");

        try {
            $files = $storage->list($prefix);
        } catch (\Throwable $e) {
            $this->error("Failed to list backups: {$e->getMessage()}");
            return self::FAILURE;
        }

        if (\count($files) === 0) {
            $this->warn("No backups found in storage.");
            return self::SUCCESS;
        }

        $headers = ['Key', 'Size (Bytes)', 'Last Modified'];
        $rows    = [];
        foreach ($files as $file) {
            if (\str_ends_with($file['key'], '.meta')) {
                continue;
            }
            $rows[] = [
                (string) $file['key'],
                (string) $file['size'],
                (string) $file['modified_at'],
            ];
        }

        $this->table($headers, $rows);
        return self::SUCCESS;
    }
}
