# MonkeysLegion Backup

A PHP library and CLI for backing up and restoring databases to local disk, Amazon S3, or Google Cloud Storage.

Built for PHP 8.4+, framework-agnostic, and designed to wrap the native database tools you already trust (`mysqldump`, `pg_dump`, `mongodump`, `redis-cli`, etc.) behind a consistent API and command-line interface.

## Features

- **Database engines** — MySQL, PostgreSQL, MongoDB, Redis, SQLite
- **Storage backends** — local filesystem, S3 (including MinIO), Google Cloud Storage
- **Compression** — optional gzip with checksum metadata sidecars
- **CLI commands** — `backup:dump`, `backup:restore`, `backup:list`, `backup:engines`, `backup:install`
- **Extensible** — register custom engines and storage adapters via the DI container

## Requirements

- PHP 8.4+
- Native CLI tools for the engines you use (e.g. `mysqldump`, `pg_dump`, `mongodump`, `redis-cli`)
- Optional: AWS SDK / Google Cloud Storage PHP client (included via Composer for S3/GCS adapters)

## Installation

```bash
composer require monkeyscloud/monkeyslegion-backup
```

Publish the configuration file into your application:

```bash
php ml backup:install
# or: php ml backup:install --format=php
```

> **Note:** The `php ml backup:*` commands work out of the box in MonkeysLegion-Skeleton. In other applications, register the commands manually by composing `monkeyscloud/monkeyslegion-cli` and `monkeyscloud/monkeyslegion-di`.

See [docs/registration_guide.md](docs/registration_guide.md) for DI container setup.

## Quick start

Dump a MySQL database to local storage:

```bash
php ml backup:dump \
  --engine=mysql \
  --database=myapp \
  --host=127.0.0.1 \
  --user=root \
  --password=secret \
  --compress
```

Restore from a stored backup:

```bash
php ml backup:restore \
  --engine=mysql \
  --database=myapp \
  --key=backups/myapp_20260702_120000.sql.gz
```

List available engines and stored backups:

```bash
php ml backup:engines
php ml backup:list
```

Credentials can also be read from environment variables (e.g. `MYSQL_HOST`, `MYSQL_USER`, `MYSQL_PASSWORD`). See [.env.example](.env.example).

## Supported engines

| Engine     | Dump tool      | Restore tool   | Notes                          |
|------------|----------------|----------------|--------------------------------|
| MySQL      | `mysqldump`    | `mysql`        | Compression supported          |
| PostgreSQL | `pg_dump`      | `psql` / `pg_restore` | Plain and custom (`-Fc`) formats |
| MongoDB    | `mongodump`    | `mongorestore` | Archive format                 |
| Redis      | `redis-cli --rdb` | RDB file copy | Restore replaces `dump.rdb`    |
| SQLite     | file copy      | file copy      | File or in-memory via PDO      |

## Storage adapters

| Driver | Use case                                      |
|--------|-----------------------------------------------|
| `local` | Filesystem storage under a configurable root |
| `s3`    | AWS S3 or S3-compatible endpoints (MinIO)    |
| `gcs`   | Google Cloud Storage                         |

Configure drivers in `config/backup.mlc` or `config/backup.php`.

## Library usage

```php
use MonkeysLegion\Backup\Engine\EngineRegistry;
use MonkeysLegion\Backup\Runner\BackupRunner;
use MonkeysLegion\Backup\ValueObject\DumpOptions;

$registry = EngineRegistry::default();
$runner   = new BackupRunner($registry, $storage, $compressor);

$result = $runner->run(new DumpOptions(
    engine: 'mysql',
    host: '127.0.0.1',
    user: 'root',
    password: 'secret',
    database: 'myapp',
    compress: true,
), 'backups/myapp.sql.gz');
```

## Testing

Unit tests run without external services:

```bash
composer test
```

Integration tests use Docker Compose for live databases and cloud emulators:

```bash
docker compose -f docker-compose.testing.yml up -d --wait
cp .env.testing .env
composer test:all
```

Other useful scripts:

```bash
composer test:db           # database engine integration tests
composer test:integration  # gcs, s3, and db groups
composer stan              # PHPStan level 8
composer ci                # stan + full test suite
```

## License

MIT — see [LICENSE](LICENSE).
