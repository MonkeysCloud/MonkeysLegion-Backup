<?php

declare(strict_types=1);

namespace MonkeysLegion\Backup\Cli\Command;

use MonkeysLegion\Cli\Console\Attributes\Command as CliCommand;

#[CliCommand('install', 'Publish the backup configuration file into your application')]
final class InstallCommand extends BaseCommand
{
    protected function handle(): int
    {
        $format = $this->getSafeOption('format', 'mlc');
        if (!\is_string($format)) {
            $format = 'mlc';
        }

        $format = \strtolower($format);

        if ($format !== 'mlc' && $format !== 'php') {
            $this->error("Invalid format \"{$format}\". Supported: mlc, php");
            return self::FAILURE;
        }

        $filename = "backup.{$format}";

        // Source lives inside this package's config/ directory
        $packageRoot = \dirname(__DIR__, 3);
        $source      = "{$packageRoot}/config/{$filename}";

        if (!\file_exists($source)) {
            $this->error("Source config file not found: {$source}");
            return self::FAILURE;
        }

        // Resolve publish destination — default to cwd/config/
        $customDest = $this->getSafeOption('path');
        if ($customDest && \is_string($customDest)) {
            $destination = \rtrim($customDest, '/\\') . "/{$filename}";
        } else {
            $destination = \getcwd() . "/config/{$filename}";
        }

        $overwrite = (bool) $this->getSafeOption('force', false);

        if ($this->fileExists($destination) && !$overwrite) {
            $this->warn("Config already exists at: {$destination}");
            $this->warn("Use --force to overwrite.");
            return self::SUCCESS;
        }

        if ($this->isDryRun()) {
            $this->info("Dry-run: would publish {$source}");
            $this->info("         → {$destination}");
            return self::SUCCESS;
        }

        $ok = $this->publish($source, $destination, $overwrite);

        if (!$ok) {
            $this->error("Failed to publish config to: {$destination}");
            return self::FAILURE;
        }

        $this->info("Config published successfully!");
        $this->info("  Format:      .{$format}");
        $this->info("  Destination: {$destination}");

        return self::SUCCESS;
    }
}
