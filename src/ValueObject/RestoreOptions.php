<?php

declare(strict_types=1);

namespace MonkeysLegion\Backup\ValueObject;

/**
 * Immutable value object holding database restore options.
 */
readonly class RestoreOptions
{
    /**
     * @param array<array-key, mixed> $customOptions Engine-specific options (e.g. format, extra flags, etc.)
     */
    public function __construct(
        public string $engine,
        public string $sourcePath,
        public ?string $host = null,
        public ?int $port = null,
        public ?string $user = null,
        public ?string $password = null,
        public ?string $database = null,
        public array $customOptions = []
    ) {}
}
