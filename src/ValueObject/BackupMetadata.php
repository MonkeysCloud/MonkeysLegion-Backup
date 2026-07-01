<?php

declare(strict_types=1);

namespace MonkeysLegion\Backup\ValueObject;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Immutable object representing the backup metadata sidecar (backup.meta.json).
 */
readonly class BackupMetadata
{
    public function __construct(
        public string $engine,
        public string $version,
        public DateTimeImmutable $createdAt,
        public string $checksum,
        public bool $compressed,
        public int $originalSize,
        public int $compressedSize
    ) {}

    /**
     * Convert metadata to array format for JSON serialization.
     *
     * @return array{
     *     engine: string,
     *     version: string,
     *     created_at: string,
     *     checksum: string,
     *     compressed: bool,
     *     original_size: int,
     *     compressed_size: int
     * }
     */
    public function toArray(): array
    {
        return [
            'engine' => $this->engine,
            'version' => $this->version,
            'created_at' => $this->createdAt->format(DateTimeImmutable::ATOM),
            'checksum' => $this->checksum,
            'compressed' => $this->compressed,
            'original_size' => $this->originalSize,
            'compressed_size' => $this->compressedSize,
        ];
    }

    /**
     * Create metadata instance from array data.
     *
     * @param array<string, mixed> $data
     * @return self
     */
    public static function fromArray(array $data): self
    {
        foreach (['engine', 'version', 'created_at', 'checksum', 'compressed', 'original_size', 'compressed_size'] as $key) {
            if (!\array_key_exists($key, $data)) {
                throw new InvalidArgumentException(\sprintf('Missing key "%s" in metadata array.', $key));
            }
        }

        $createdAt = DateTimeImmutable::createFromFormat(DateTimeImmutable::ATOM, (string)$data['created_at']);
        if ($createdAt === false) {
            throw new InvalidArgumentException('Invalid created_at timestamp format, must be ATOM format.');
        }

        return new self(
            engine: (string)$data['engine'],
            version: (string)$data['version'],
            createdAt: $createdAt,
            checksum: (string)$data['checksum'],
            compressed: (bool)$data['compressed'],
            originalSize: (int)$data['original_size'],
            compressedSize: (int)$data['compressed_size']
        );
    }

    /**
     * Serialize to JSON.
     */
    public function toJson(): string
    {
        return \json_encode($this->toArray(), JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
    }

    /**
     * Deserialize from JSON string.
     */
    public static function fromJson(string $json): self
    {
        $data = \json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        if (!\is_array($data)) {
            throw new InvalidArgumentException('Invalid JSON metadata.');
        }
        return self::fromArray($data);
    }
}
