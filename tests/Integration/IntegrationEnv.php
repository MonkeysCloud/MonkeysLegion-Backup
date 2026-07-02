<?php

declare(strict_types=1);

namespace MonkeysLegion\Backup\Tests\Integration;

trait IntegrationEnv
{
    /**
     * @return array<string, string>
     */
    private function loadEnv(): array
    {
        $envFile = \dirname(__DIR__, 2) . '/.env';
        if (!\is_readable($envFile)) {
            return [];
        }

        $result = [];
        $lines  = \file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return [];
        }

        foreach ($lines as $line) {
            $line = \trim($line);
            if ($line === '' || $line[0] === '#') {
                continue;
            }
            [$key, $val] = \explode('=', $line, 2) + ['', ''];
            $result[\trim($key)] = \trim($val);
        }

        return $result;
    }

    private function isTcpReachable(string $host, int $port): bool
    {
        $socket = @\fsockopen($host, $port, $errno, $errstr, 2);

        if ($socket === false) {
            return false;
        }

        \fclose($socket);

        return true;
    }

    private function skipUnlessDockerServiceReachable(string $service, string $host, int $port): void
    {
        if (!$this->isTcpReachable($host, $port)) {
            $this->markTestSkipped(
                "{$service} is not running. Start it with: " .
                'docker compose -f docker-compose.testing.yml up -d --wait'
            );
        }
    }
}
