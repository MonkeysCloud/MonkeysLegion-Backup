<?php

declare(strict_types=1);

namespace MonkeysLegion\Backup\Storage;

use MonkeysLegion\Backup\Contract\StorageAdapterInterface;
use MonkeysLegion\Backup\Exception\StorageAdapterNotFoundException;
use MonkeysLegion\Backup\ValueObject\StorageConfig;

final class StorageAdapterFactory
{
    /**
     * @var array<string, string>
     */
    private static array $drivers = [
        'local' => 'MonkeysLegion\\Backup\\Storage\\LocalStorageAdapter',
        'gcs' => 'MonkeysLegion\\Backup\\Storage\\GcsStorageAdapter',
        's3' => 'MonkeysLegion\\Backup\\Storage\\S3StorageAdapter',
    ];

    /**
     * @param array<string, class-string<StorageAdapterInterface>> $custom
     */
    public function __construct(
        private array $custom = []
    ) {}

    /**
     * Register a custom storage adapter class globally.
     *
     * @param string $name
     * @param class-string<StorageAdapterInterface> $adapterClass
     */
    public static function register(string $name, string $adapterClass): void
    {
        self::$drivers[$name] = $adapterClass;
    }

    /**
     * Create a storage adapter from config.
     */
    public static function fromConfig(StorageConfig $config): StorageAdapterInterface
    {
        $driver = $config->driver();
        $options = $config->all();

        return (new self())->create($driver, $options);
    }

    /**
     * Create a storage adapter instance.
     *
     * @param string $name
     * @param array<string, mixed> $options
     */
    public function create(string $name, array $options = []): StorageAdapterInterface
    {
        $class = $this->custom[$name] ?? self::$drivers[$name] ?? null;

        if ($class === null) {
            throw new StorageAdapterNotFoundException(\sprintf('Adapter "%s" is not registered.', $name));
        }

        if (!\class_exists($class)) {
            $hint = match ($name) {
                'gcs' => 'Install monkeysbackup/storage-gcs or register a custom adapter.',
                's3' => 'Install monkeysbackup/storage-s3 or register a custom adapter.',
                default => \sprintf('Install package for adapter "%s" or register a custom adapter.', $name),
            };
            throw new StorageAdapterNotFoundException(\sprintf(
                'Adapter "%s" is not available. %s',
                $name,
                $hint
            ));
        }

        /** @var StorageAdapterInterface */
        return new $class($options);
    }
}
