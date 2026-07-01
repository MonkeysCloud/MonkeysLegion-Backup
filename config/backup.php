<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Default Database Engine Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may specify which of the database engines is used by default
    | for backups and restores.
    |
    */

    'default_engine' => env('BACKUP_DEFAULT_ENGINE', 'mysql'),

    /*
    |--------------------------------------------------------------------------
    | Default Storage Driver Configuration
    |--------------------------------------------------------------------------
    |
    | This option controls the default storage driver that will be used to
    | store your database backups.
    |
    | Supported: "local", "s3", "gcs"
    |
    */

    'default_storage' => env('BACKUP_STORAGE_DRIVER', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Storage Destinations
    |--------------------------------------------------------------------------
    |
    | Here you can configure all the storage backends available for backup
    | uploads. Configured drivers are resolved using the factory.
    |
    */

    'storage' => [

        'local' => [
            'driver' => 'local',
            'root'   => env('BACKUP_STORAGE_ROOT', 'var/backups'),
        ],

        's3' => [
            'driver'         => 's3',
            'bucket'         => env('BACKUP_STORAGE_BUCKET', ''),
            'region'         => env('BACKUP_STORAGE_REGION', 'us-east-1'),
            'key'            => env('BACKUP_STORAGE_KEY', ''),
            'secret'         => env('BACKUP_STORAGE_SECRET', ''),
            'endpoint'       => env('BACKUP_STORAGE_ENDPOINT', null),
            'use_path_style' => env('BACKUP_STORAGE_PATH_STYLE', false),
        ],

        'gcs' => [
            'driver'             => 'gcs',
            'bucket'             => env('BACKUP_STORAGE_BUCKET', ''),
            'projectId'          => env('BACKUP_STORAGE_PROJECT_ID', ''),
            'keyFilePath'        => env('BACKUP_STORAGE_KEY_FILE', null),
            'credentialsFetcher' => null, // Optional custom credentials fetcher instance/callback
            'apiEndpoint'        => env('BACKUP_STORAGE_ENDPOINT', null),
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Compression Settings
    |--------------------------------------------------------------------------
    |
    | Configure the default compression settings used for backup artifacts.
    |
    */

    'compression' => [
        'enabled' => env('BACKUP_COMPRESSION_ENABLED', true),
        'driver'  => 'gzip',
    ],

];
