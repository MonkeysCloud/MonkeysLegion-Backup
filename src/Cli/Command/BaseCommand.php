<?php

declare(strict_types=1);

namespace MonkeysLegion\Backup\Cli\Command;

use MonkeysLegion\Cli\Console\Command;
use MonkeysLegion\DI\Traits\ContainerAware;
use MonkeysLegion\Backup\Contract\LoggerInterface;

abstract class BaseCommand extends Command
{
    use ContainerAware;

    protected function getSafeOption(string $name, mixed $default = null): mixed
    {
        global $argv;
        $long = "--{$name}";
        foreach ($argv as $i => $arg) {
            if (\str_starts_with($arg, "{$long}=")) {
                return \substr($arg, \strlen($long) + 1);
            }
            if ($arg === $long) {
                if (isset($argv[$i + 1]) && !\str_starts_with($argv[$i + 1], '-')) {
                    return $argv[$i + 1];
                }
                return true;
            }
        }

        $shortMap = [
            'config'   => '-c',
            'engine'   => '-e',
            'database' => '-d',
            'host'     => '-h',
            'port'     => '-p',
            'user'     => '-u',
            'password' => '-P',
            'key'      => '-k',
        ];

        if (isset($shortMap[$name])) {
            $short = $shortMap[$name];
            foreach ($argv as $i => $arg) {
                if ($arg === $short) {
                    if (isset($argv[$i + 1]) && !\str_starts_with($argv[$i + 1], '-')) {
                        return $argv[$i + 1];
                    }
                    return true;
                }
            }
        }

        return $default;
    }

    protected function isDryRun(): bool
    {
        global $argv;
        return \in_array('--dry-run', $argv, true) || \in_array('--dry', $argv, true);
    }

    protected function getDbOption(string $engine, string $name, mixed $default = null): mixed
    {
        $val = $this->getSafeOption($name);
        if ($val !== null && $val !== true) {
            return $val;
        }

        $prefix = match ($engine) {
            'mysql'                  => 'MYSQL_',
            'postgres', 'postgresql' => 'PGSQL_',
            'mongodb', 'mongo'       => 'MONGO_',
            'redis'                  => 'REDIS_',
            default                  => null,
        };

        if ($prefix !== null) {
            $upperName = \strtoupper($name);
            $keys = $name === 'database'
                ? [
                    "{$prefix}DATABASE",
                    "{$prefix}DB",
                    "{$prefix}TEST_DB_DUMP",
                    "{$prefix}TEST_DB",
                    'DB_DATABASE',
                ]
                : [
                    "{$prefix}{$upperName}",
                    "DB_{$upperName}",
                ];

            foreach ($keys as $key) {
                $envVal = \getenv($key);
                if ($envVal !== false && $envVal !== '') {
                    return $envVal;
                }
            }
        }

        return $default;
    }

    /**
     * @var list<string>|null
     */
    public static ?array $capturedOutput = null;

    protected function info(string $msg): void
    {
        if (self::$capturedOutput !== null) {
            self::$capturedOutput[] = "info: {$msg}";
            return;
        }
        parent::info($msg);
    }

    protected function line(string $msg): void
    {
        if (self::$capturedOutput !== null) {
            self::$capturedOutput[] = "line: {$msg}";
            return;
        }
        parent::line($msg);
    }

    protected function error(string $msg): void
    {
        if (self::$capturedOutput !== null) {
            self::$capturedOutput[] = "error: {$msg}";
            return;
        }
        parent::error($msg);
    }

    protected function warn(string $msg): void
    {
        if (self::$capturedOutput !== null) {
            self::$capturedOutput[] = "warn: {$msg}";
            return;
        }
        parent::warn($msg);
    }

    protected function table(array $headers, array $rows, array $align = []): void
    {
        if (self::$capturedOutput !== null) {
            self::$capturedOutput[] = (new \MonkeysLegion\Cli\Console\Output\TableRenderer())->build($headers, $rows, $align);
            return;
        }
        parent::table($headers, $rows, $align);
    }

    public function logMessage(string $message): void
    {
        $this->info("  [LOG] {$message}");
    }

    protected function resolveLogger(): ?LoggerInterface
    {
        try {
            if ($this->container()->has(\MonkeysLegion\Logger\LoggerInterface::class)) {
                $mlLogger = $this->container()->get(\MonkeysLegion\Logger\LoggerInterface::class);
                if ($mlLogger instanceof \MonkeysLegion\Logger\LoggerInterface) {
                    return new class($mlLogger) implements LoggerInterface {
                        public function __construct(private \MonkeysLegion\Logger\LoggerInterface $logger) {}
                        public function log(string $message, array $context = []): void
                        {
                            $this->logger->info($message, $context);
                        }
                    };
                }
            }
        } catch (\Throwable) {
        }
        return null;
    }
}
