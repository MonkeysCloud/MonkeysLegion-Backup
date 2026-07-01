<?php

declare(strict_types=1);

namespace MonkeysLegion\Backup\ValueObject;

/**
 * Immutable value object holding database dump options.
 */
readonly class DumpOptions
{
    /**
     * @param array<array-key, mixed> $customOptions Engine-specific options (e.g. format, single-transaction, etc.)
     */
    public function __construct(
        public string $engine,
        public ?string $host = null,
        public ?int $port = null,
        public ?string $user = null,
        public ?string $password = null,
        public ?string $database = null,
        public bool $compress = false,
        public array $customOptions = []
    ) {}
}
