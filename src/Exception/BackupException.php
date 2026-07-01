<?php

declare(strict_types=1);

namespace MonkeysLegion\Backup\Exception;

use Throwable;

class BackupException extends \RuntimeException
{
    /**
     * @param array<string, mixed> $context
     */
    public function __construct(
        string $message = "",
        int $code = 0,
        private array $context = [],
        ?Throwable $previous = null
    ) {
        parent::__construct($message, $code, $previous);
    }

    /**
     * Get exception context.
     *
     * @return array<string, mixed>
     */
    public function getContext(): array
    {
        return $this->context;
    }
}
