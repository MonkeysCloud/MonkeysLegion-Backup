<?php

declare(strict_types=1);

namespace MonkeysLegion\Backup\ValueObject;

use InvalidArgumentException;

/**
 * Immutable value object holding configurations for storage adapters.
 */
readonly class StorageConfig
{
    /**
     * @param array<string, mixed> $config
     */
    public function __construct(
        private array $config
    ) {
        if (!isset($this->config['driver'])) {
            throw new InvalidArgumentException('Storage config must specify a "driver".');
        }
    }

    /**
     * Create storage config from array configuration.
     *
     * @param array<string, mixed> $config
     * @return self
     */
    public static function fromArray(array $config): self
    {
        return new self($config);
    }

    /**
     * Create storage config from JSON file path.
     *
     * @param string $filePath
     * @return self
     */
    public static function fromJsonFile(string $filePath): self
    {
        if (!\is_file($filePath) || !\is_readable($filePath)) {
            throw new InvalidArgumentException(\sprintf('Config file "%s" does not exist or is not readable.', $filePath));
        }

        $json = \file_get_contents($filePath);
        if ($json === false) {
            throw new InvalidArgumentException(\sprintf('Failed to read config file "%s".', $filePath));
        }

        $data = \json_decode($json, true);
        if (\json_last_error() !== JSON_ERROR_NONE || !\is_array($data)) {
            throw new InvalidArgumentException(\sprintf('Invalid JSON in config file "%s".', $filePath));
        }

        return new self($data);
    }

    /**
     * Get the configured storage driver name (e.g. 'local', 'gcs', 's3').
     */
    public function driver(): string
    {
        return (string)$this->config['driver'];
    }

    /**
     * Get a specific configuration key value.
     */
    public function get(string $key, mixed $default = null): mixed
    {
        return $this->config[$key] ?? $default;
    }

    /**
     * Get the full configuration array.
     *
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return $this->config;
    }
}
