<?php

declare(strict_types=1);

namespace MonkeysLegion\Backup\Contract;

interface LoggerInterface
{
    /**
     * Log a message with an optional context array.
     *
     * @param string $message
     * @param array<string, mixed> $context
     */
    public function log(string $message, array $context = []): void;
}
