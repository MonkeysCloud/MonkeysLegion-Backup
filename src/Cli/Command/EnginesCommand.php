<?php

declare(strict_types=1);

namespace MonkeysLegion\Backup\Cli\Command;

use MonkeysLegion\Cli\Console\Attributes\Command as CliCommand;
use MonkeysLegion\Backup\Engine\EngineRegistry;

#[CliCommand('engines', 'List all registered backup engines')]
final class EnginesCommand extends BaseCommand
{
    protected function handle(): int
    {
        try {
            $registry = $this->container()->get(EngineRegistry::class);
        } catch (\Throwable) {
            $this->error("EngineRegistry is not registered in the container.");
            return self::FAILURE;
        }

        $engines = $registry->all();

        $this->info("Registered backup engines:");

        $headers = ['Engine Name', 'Class', 'Compression Support'];
        $rows    = [];

        foreach ($engines as $name => $engine) {
            $compSupport = $engine->supports('compression') ? 'Yes' : 'No';
            $engineClass = (string) \get_class($engine);
            $rows[]      = [
                (string) $name,
                $engineClass,
                $compSupport,
            ];
        }

        $this->table($headers, $rows);

        return self::SUCCESS;
    }
}
