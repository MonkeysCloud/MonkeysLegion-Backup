# MonkeysLegion Backup — DI Registration & Integration Guide

This guide describes how to configure and register the backup package services (adapters, engines, runners) with the `monkeyscloud/monkeyslegion-di` container in your host application.

---

## 1. Automated Command Discovery

Since this package implements the `monkeyscloud/monkeyslegion-cli` conventions, any class extending `MonkeysLegion\Cli\Console\Command` under the `Cli/Command` PSR-4 directory will be auto-discovered by the main application's `CliKernel`.

No manual command registration is required!

---

## 2. Container Service Registration

To run the backup and restore operations, the container needs to resolve the dependencies of `BackupRunner` and `RestoreRunner`:
- `EngineRegistry`
- `StorageAdapterInterface`
- `CompressorInterface`
- `LoggerInterface` (optional)

Below is the bootstrap code to register these services in your container bootstrap file (e.g. `bootstrap/app.php` or a Service Provider class):

```php
<?php

declare(strict_types=1);

use MonkeysLegion\DI\Container;
use MonkeysLegion\Backup\Contract\StorageAdapterInterface;
use MonkeysLegion\Backup\Contract\CompressorInterface;
use MonkeysLegion\Backup\Contract\LoggerInterface;
use MonkeysLegion\Backup\Engine\EngineRegistry;
use MonkeysLegion\Backup\Compressor\GzipCompressor;
use MonkeysLegion\Backup\Storage\StorageAdapterFactory;
use MonkeysLegion\Backup\ValueObject\StorageConfig;

/** @var Container $container */
$container = Container::instance();

// 1. Register Engine Registry with built-in engines
$container->set(EngineRegistry::class, function () {
    return EngineRegistry::default();
});

// 2. Register Compressor Interface
$container->bind(CompressorInterface::class, GzipCompressor::class);

// 3. Register Storage Adapter Interface using the config file
$container->set(StorageAdapterInterface::class, function () {
    // Read config from standard config location
    $config = require base_path('config/backup.php');
    
    $driver = $config['default_storage'] ?? 'local';
    $storageOptions = $config['storage'][$driver] ?? [];
    
    $storageConfig = StorageConfig::fromArray($storageOptions);
    
    return StorageAdapterFactory::fromConfig($storageConfig);
});
```

---

## 3. Running from Custom Code

Once registered in the container, you can retrieve the runners directly via constructor injection or using the `ContainerAware` trait:

```php
use MonkeysLegion\Backup\Runner\BackupRunner;
use MonkeysLegion\Backup\ValueObject\DumpOptions;

$runner = $container->get(BackupRunner::class);

$options = new DumpOptions(
    engine: 'mysql',
    host: '127.0.0.1',
    port: 3306,
    user: 'root',
    password: 'password',
    database: 'production_db',
    compress: true
);

$result = $runner->run($options, 'backups/prod_backup.sql.gz');
```
