# MonkeysBackup — v1 Roadmap

> **Project:** MonkeysBackup  
> **License:** MIT (open source)  
> **Package:** `monkeysbackup/monkeysbackup`  
> **Namespace:** `MonkeysBackup\`  
> **PHP:** `^8.4`  
> **Status:** Greenfield — v1 not started  
> **Target:** v1.0.0 — full dump/restore library + CLI + pluggable storage

---

## What MonkeysBackup is

MonkeysBackup is a **PHP library and CLI** for backing up and restoring databases. You point it at a database engine, it runs the right native tools (`mysqldump`, `pg_dump`, `mongodump`, …), optionally compresses the output, and pushes the artifact to storage you choose.

It is designed to be embedded in cron jobs, queue workers, deployment scripts, or any PHP application — without pulling in a framework, ORM, or cloud SDK in the **core** package.

**You bring:**

- Database credentials and network access to the DB host
- Native client binaries on the machine where backup runs (`mysqldump`, etc.)
- A storage adapter (local disk by default; GCS/S3 via optional packages)

**MonkeysBackup brings:**

- Engine-specific dump and restore orchestration
- Compression, checksums, metadata sidecars
- A small CLI for operators
- A factory to wire storage backends without coupling the core to any vendor SDK

---

## v1 goals

| Goal | v1 |
|------|-----|
| MySQL, PostgreSQL, MongoDB, Redis, SQLite backup & restore | ✅ |
| gzip compression | ✅ |
| Local filesystem storage (built-in) | ✅ |
| GCS and S3-compatible storage (optional adapter packages) | ✅ |
| `StorageAdapterFactory` — config-driven backend selection | ✅ |
| CLI: `dump`, `restore`, `engines` | ✅ |
| Library API: `BackupRunner`, `RestoreRunner` | ✅ |
| Metadata JSON sidecar per backup (engine, size, checksum, timestamp) | ✅ |
| Unit tests without live databases (command + contract tests) | ✅ |
| Integration tests with Docker Compose (CI) | ✅ |
| **Zero Composer dependencies in core** | ✅ |

---

## v1 non-goals (later versions)

| Feature | Version |
|---------|---------|
| Point-in-time recovery (binlog / WAL replay) | v1.2 |
| Backup scheduling / cron daemon | Out of scope — use system cron |
| Web UI or REST API | Out of scope — consumers build their own |
| Backup encryption at rest | v1.3 |
| Cross-region replication | Out of scope |
| Managed multi-tenant bucket provisioning | Out of scope |
| Automatic schema migration detection | Out of scope |

---

## Dependency policy

### Core package (`monkeysbackup/monkeysbackup`)

**Composer `require` — nothing beyond PHP extensions:**

```json
{
  "require": {
    "php": "^8.4",
    "ext-json": "*"
  }
}
```

No PSR-3, no Symfony Process, no cloud SDKs. Subprocess execution uses PHP `proc_open`. Logging is an optional callable or internal no-op interface defined in the core namespace.

### Optional adapter packages (install only what you use)

Each adapter lives in its **own** Composer package so its SDK dependency never lands in projects that only need local disk.

| Package | Composer require (adapter only) | Factory key |
|---------|----------------------------------|-------------|
| `monkeysbackup/storage-local` | *(none — ships inside core)* | `local` |
| `monkeysbackup/storage-gcs` | `google/cloud-storage: ^1.30` | `gcs` |
| `monkeysbackup/storage-s3` | `aws/aws-sdk-php: ^3.300` | `s3` |
| `monkeysbackup/storage-ftp` | *(future)* | `ftp` |

Core `composer.json` uses **`suggest`** only:

```json
{
  "suggest": {
    "monkeysbackup/storage-gcs": "Google Cloud Storage adapter",
    "monkeysbackup/storage-s3": "S3-compatible adapter (AWS, MinIO, etc.)"
  }
}
```

### Runtime discovery

`StorageAdapterFactory` resolves adapters by name. If the adapter package is not installed, it throws a clear exception:

```
StorageAdapterNotFoundException: Adapter "gcs" is not available.
Install monkeysbackup/storage-gcs or register a custom adapter.
```

Custom adapters implement `MonkeysBackup\Contract\StorageAdapterInterface` and register via `StorageAdapterFactory::register('my-backend', MyAdapter::class)`.

---

## Architecture

### Layer diagram

```
┌──────────────────────────────────────────────────────────────────┐
│  CLI (bin/monkeys-backup)                                        │
│  dump · restore · engines · list                                 │
└────────────────────────────┬─────────────────────────────────────┘
                             │
┌────────────────────────────▼─────────────────────────────────────┐
│  Runners                                                         │
│  BackupRunner  → dump → compress → checksum → metadata → upload  │
│  RestoreRunner → download → verify → decompress → restore          │
└─────┬──────────────────┬──────────────────┬──────────────────────┘
      │                  │                  │
      ▼                  ▼                  ▼
┌───────────┐    ┌───────────────┐   ┌─────────────────────────────┐
│ Engines   │    │ Compression   │   │ Storage                     │
│ Registry  │    │ GzipCompressor│   │ StorageAdapterFactory       │
│ MySQL     │    │ (ext-zlib)    │   │   ├─ local  (core)          │
│ Postgres  │    │               │   │   ├─ gcs    (optional pkg)  │
│ MongoDB   │    │               │   │   └─ s3     (optional pkg)  │
│ Redis     │    │               │   │ StorageAdapterInterface     │
│ SQLite    │    │               │   └─────────────────────────────┘
└───────────┘    └───────────────┘
      │
      ▼
 Native CLI tools on host: mysqldump, pg_dump, mongodump, redis-cli, …
```

### Package layout (monorepo or split repos)

```
monkeysbackup/
├── src/
│   ├── Contract/
│   │   ├── EngineInterface.php
│   │   ├── StorageAdapterInterface.php
│   │   ├── CompressorInterface.php
│   │   └── LoggerInterface.php          # internal minimal interface
│   ├── Engine/
│   │   ├── EngineRegistry.php
│   │   ├── MysqlEngine.php
│   │   ├── PostgresEngine.php
│   │   ├── MongodbEngine.php
│   │   ├── RedisEngine.php
│   │   └── SqliteEngine.php
│   ├── ValueObject/
│   │   ├── DumpOptions.php
│   │   ├── RestoreOptions.php
│   │   ├── BackupArtifact.php
│   │   ├── BackupMetadata.php
│   │   └── StorageConfig.php
│   ├── Compression/
│   │   └── GzipCompressor.php
│   ├── Storage/
│   │   ├── StorageAdapterFactory.php
│   │   └── LocalStorageAdapter.php
│   ├── Runner/
│   │   ├── BackupRunner.php
│   │   └── RestoreRunner.php
│   ├── Process/
│   │   └── ProcessRunner.php            # proc_open wrapper
│   └── Exception/
│       ├── BackupException.php
│       ├── EngineException.php
│       └── StorageAdapterNotFoundException.php
├── bin/
│   └── monkeys-backup
├── packages/                            # optional adapters (publish separately)
│   ├── storage-gcs/
│   └── storage-s3/
├── tests/
├── docker-compose.yml                   # integration CI
└── docs/
    ├── getting-started.md
    └── adr/
        └── 001-zero-core-dependencies.md
```

### Core interfaces

**Engine**

```php
interface EngineInterface
{
    public function name(): string;  // mysql, postgres, …

    public function dump(DumpOptions $options): BackupArtifact;

    public function restore(RestoreOptions $options): void;

    public function supports(string $feature): bool;  // e.g. compression, archive
}
```

**Storage**

```php
interface StorageAdapterInterface
{
    public function upload(string $localPath, string $remoteKey, array $metadata = []): string;

    public function download(string $remoteKey, string $localPath): void;

    public function delete(string $remoteKey): void;

    /** @return list<array{key: string, size: int, modified_at: string}> */
    public function list(string $prefix = ''): array;
}
```

**Factory**

```php
final class StorageAdapterFactory
{
    /** @param array<string, class-string<StorageAdapterInterface>> $custom */
    public function __construct(private array $custom = []) {}

    public static function fromConfig(StorageConfig $config): StorageAdapterInterface;

    public function register(string $name, string $adapterClass): void;

    public function create(string $name, array $options = []): StorageAdapterInterface;
}
```

**Config example (PHP array or JSON file)**

```php
$factory = StorageAdapterFactory::fromConfig(new StorageConfig([
    'driver' => 'gcs',
    'bucket' => 'my-backups',
    'prefix' => 'prod/mysql/',
    'credentials' => '/path/to/service-account.json',  // or env GOOGLE_APPLICATION_CREDENTIALS
]));
```

```php
// Local only — zero extra packages
$factory = StorageAdapterFactory::fromConfig(new StorageConfig([
    'driver' => 'local',
    'root' => '/var/backups',
]));
```

### Backup artifact flow

1. `BackupRunner::run(DumpOptions, StorageConfig)`  
2. Resolve engine from `EngineRegistry`  
3. Engine writes dump to temp file  
4. Optional `GzipCompressor`  
5. Compute SHA-256 checksum  
6. Write `backup.meta.json` sidecar  
7. `StorageAdapterFactory` uploads dump + meta  
8. Return `BackupResult` (remote key, size, checksum, duration)

Restore reverses the chain. Passwords are passed via env vars or options objects — never echoed to logs or CLI output.

---

## Supported engines (v1)

| Engine | Dump tool | Restore tool | Format | Notes |
|--------|-----------|--------------|--------|-------|
| **MySQL** | `mysqldump` | `mysql` | SQL plain | `--single-transaction` default for InnoDB |
| **PostgreSQL** | `pg_dump` | `psql` / `pg_restore` | plain or custom | `-Fc` custom format optional |
| **MongoDB** | `mongodump --archive` | `mongorestore` | archive | auth db configurable |
| **Redis** | `redis-cli --rdb` | RDB file replace | RDB binary | Document: restore may need restart |
| **SQLite** | file copy | file copy | `.db` file | Path-based, no server |

Engines validate that required binaries exist on `PATH` before running and surface actionable errors.

---

## CLI (v1)

```bash
# List supported engines
monkeys-backup engines

# Dump MySQL to local storage
MYSQL_PWD=secret monkeys-backup dump \
  --engine=mysql \
  --host=127.0.0.1 --port=3306 --user=root --database=app \
  --storage=local --storage-root=/backups \
  --compress

# Dump PostgreSQL to GCS (requires monkeysbackup/storage-gcs)
PGPASSWORD=secret monkeys-backup dump \
  --engine=postgres \
  --host=127.0.0.1 --database=app \
  --storage=gcs --bucket=my-bucket --prefix=pg/ \
  --config=/etc/monkeysbackup/storage.json

# Restore latest matching prefix
monkeys-backup restore \
  --engine=mysql \
  --host=127.0.0.1 --user=root --database=app \
  --storage=local --storage-root=/backups \
  --key=2026-06-27_120000/app.sql.gz
```

Exit codes: `0` success, `1` validation, `2` engine failure, `3` storage failure, `4` adapter missing.

---

## Library usage (v1)

```php
use MonkeysBackup\Engine\EngineRegistry;
use MonkeysBackup\Runner\BackupRunner;
use MonkeysBackup\Storage\StorageAdapterFactory;
use MonkeysBackup\ValueObject\DumpOptions;
use MonkeysBackup\ValueObject\StorageConfig;

$registry = EngineRegistry::default();
$storage = StorageAdapterFactory::fromConfig(StorageConfig::fromArray([
    'driver' => 's3',
    'bucket' => 'backups',
    'region' => 'eu-west-1',
    'endpoint' => 'https://minio.example.com',  // optional, for MinIO
]));

$runner = new BackupRunner($registry, $storage);

$result = $runner->run(new DumpOptions(
    engine: 'postgres',
    host: '10.0.0.5',
    port: 5432,
    user: 'app',
    password: getenv('PGPASSWORD'),
    database: 'production',
    compress: true,
));

// $result->remoteKey(), $result->checksum(), $result->sizeBytes()
```

---

## v1 feature checklist

### Core library

- [ ] `EngineInterface` + `EngineRegistry` with auto-registration of built-in engines
- [ ] `DumpOptions` / `RestoreOptions` immutable value objects
- [ ] `BackupArtifact`, `BackupMetadata`, `BackupResult`
- [ ] `ProcessRunner` — timeout, stderr capture, no shell injection (argv array only)
- [ ] `GzipCompressor` using `ext-zlib`
- [ ] SHA-256 checksum on every artifact
- [ ] `backup.meta.json` sidecar (engine, version, created_at, checksum, compressed, original_size)
- [ ] `BackupRunner` and `RestoreRunner`
- [ ] Exception hierarchy with context arrays (no passwords in messages)

### Storage

- [ ] `StorageAdapterInterface`
- [ ] `LocalStorageAdapter` (core)
- [ ] `StorageAdapterFactory` with `local`, `gcs`, `s3` keys
- [ ] `StorageConfig` parser (array + JSON file)
- [ ] `register()` for custom adapters
- [ ] `monkeysbackup/storage-gcs` package → `GcsStorageAdapter`
- [ ] `monkeysbackup/storage-s3` package → `S3StorageAdapter` (AWS + custom endpoint for MinIO)

### CLI

- [ ] `bin/monkeys-backup` — `dump`, `restore`, `engines`, `list`
- [ ] `--config` for storage JSON
- [ ] Credentials from env vars per engine convention
- [ ] `--dry-run` prints planned commands without executing (masks secrets)

### Quality

- [ ] PHPUnit: command string tests per engine
- [ ] PHPUnit: factory throws when adapter package missing
- [ ] PHPStan level 8
- [ ] GitHub Actions: unit + integration (docker-compose MySQL + Postgres)
- [ ] README with install, CLI, library, adapter packages
- [ ] `CHANGELOG.md`, `LICENSE` (MIT), `CONTRIBUTING.md`

---

## Build plan (v1.0)

**Total estimate: ~11 developer-days** (Trello card sums are the upper bound; engines share one pattern.)

| Week | Focus | Cards | Days |
|------|-------|-------|------|
| 1 | Scaffold + contracts + factory | MB-01 → MB-03 | 2.5 |
| 2 | MySQL + PostgreSQL engines | MB-04 → MB-05 | 2 |
| 3 | MongoDB + Redis + SQLite + compression | MB-06 → MB-08 | 2.5 |
| 4 | Runners + local storage + factory wiring | MB-09 → MB-11 | 2 |
| 5 | GCS + S3 adapter packages | MB-12 → MB-13 | 2 |
| 6 | CLI + tests + v1.0.0 release | MB-14 → MB-16 | 2 |

---

## Trello tasks (v1)

> Prefix: **`MB-xx`**. Labels: `[Core]` `[Engine]` `[Storage]` `[CLI]` `[QA]` `[Docs]`

---

### List 1 · Foundation

#### `[MB-01]` Repository scaffold

**Description:**  
Monorepo or single repo with PSR-4 `MonkeysBackup\`, MIT license, PHP 8.4, PHPStan, PHPUnit, GitHub Actions. Core `composer.json` has **zero** runtime dependencies.

**Acceptance criteria:**

- `composer test` passes on empty suite
- ADR `001-zero-core-dependencies.md` committed

**Estimate:** 1d · **Labels:** Core, Docs

---

#### `[MB-02]` Contracts & value objects

**Description:**  
`EngineInterface`, `StorageAdapterInterface`, `CompressorInterface`, `LoggerInterface` (internal). `DumpOptions`, `RestoreOptions`, `BackupArtifact`, `BackupMetadata`, `StorageConfig`, `BackupResult`.

**Acceptance criteria:**

- All value objects immutable
- `StorageConfig::fromArray()` and `fromJsonFile()`

**Depends on:** MB-01 · **Estimate:** 1d · **Labels:** Core

---

#### `[MB-03]` StorageAdapterFactory

**Description:**  
Factory resolves `local`, `gcs`, `s3` by name. Built-in map points `local` → `LocalStorageAdapter`. GCS/S3 classes live in optional packages; factory checks `class_exists` and throws `StorageAdapterNotFoundException` with install hint. Support `register()` for custom backends.

**Acceptance criteria:**

- Unit test: `gcs` without package installed → clear exception message
- Unit test: custom adapter via `register()`

**Depends on:** MB-02 · **Estimate:** 1.5d · **Labels:** Storage, Core

---

### List 2 · Engines

#### `[MB-04]` MySQL engine

**Description:**  
`mysqldump` / `mysql` via `ProcessRunner`. Options: `--single-transaction`, `--routines`, `--triggers`. Unit tests assert argv arrays, not shell strings.

**Depends on:** MB-02 · **Estimate:** 1.5d · **Labels:** Engine

---

#### `[MB-05]` PostgreSQL engine

**Description:**  
`pg_dump` / `psql` or `pg_restore`. Plain and custom format (`-Fc`) behind option flag.

**Depends on:** MB-02 · **Estimate:** 1.5d · **Labels:** Engine

---

#### `[MB-06]` MongoDB, Redis, SQLite engines

**Description:**  
`mongodump --archive`, `redis-cli --rdb`, SQLite file copy. Document Redis restore caveats in engine docblock + README.

**Depends on:** MB-02 · **Estimate:** 2d · **Labels:** Engine

---

#### `[MB-07]` EngineRegistry

**Description:**  
`EngineRegistry::default()` registers all five engines. `get(string $name): EngineInterface`. Unknown engine → `EngineException`.

**Depends on:** MB-04 → MB-06 · **Estimate:** 0.5d · **Labels:** Core

---

### List 3 · Compression & process

#### `[MB-08]` GzipCompressor + ProcessRunner

**Description:**  
`GzipCompressor` wrap/unwrap. `ProcessRunner` with timeout, env injection, stderr merge, secret redaction in debug output.

**Depends on:** MB-02 · **Estimate:** 1.5d · **Labels:** Core

---

### List 4 · Runners & local storage

#### `[MB-09]` LocalStorageAdapter

**Description:**  
Implements `StorageAdapterInterface` for local disk. Creates nested directories. Atomic write via temp file + rename.

**Depends on:** MB-03 · **Estimate:** 1d · **Labels:** Storage

---

#### `[MB-10]` BackupRunner & RestoreRunner

**Description:**  
Full orchestration: dump → compress → checksum → meta JSON → upload. Restore: download → verify checksum → decompress → restore. Never log passwords.

**Depends on:** MB-07, MB-08, MB-09 · **Estimate:** 2d · **Labels:** Core

---

### List 5 · Optional adapter packages

#### `[MB-11]` Package `monkeysbackup/storage-gcs`

**Description:**  
Separate `composer.json` requiring `google/cloud-storage`. `GcsStorageAdapter` implements interface. Register with factory via composer autoload + small `AdapterRegistrar` or documented manual `register('gcs', GcsStorageAdapter::class)`.

**Depends on:** MB-03 · **Estimate:** 1.5d · **Labels:** Storage

---

#### `[MB-12]` Package `monkeysbackup/storage-s3`

**Description:**  
Separate package requiring `aws/aws-sdk-php`. `S3StorageAdapter` with `endpoint` option for MinIO. Path-style vs virtual-hosted config.

**Depends on:** MB-03 · **Estimate:** 1.5d · **Labels:** Storage

---

### List 6 · CLI & release

#### `[MB-13]` CLI `monkeys-backup`

**Description:**  
Symfony Console **not** used — plain `argv` parsing to keep core zero-dep. Commands: `engines`, `dump`, `restore`, `list`. `--dry-run`, `--config`, env-based credentials.

**Depends on:** MB-10 · **Estimate:** 1.5d · **Labels:** CLI

---

#### `[MB-14]` Unit test suite

**Description:**  
Engine command tests, factory tests, compressor round-trip, runner with mocked storage/engine.

**Depends on:** MB-10 · **Estimate:** 1.5d · **Labels:** QA

---

#### `[MB-15]` Integration tests (Docker Compose)

**Description:**  
CI job: MySQL + PostgreSQL containers, real dump → local storage → restore → row count assert.

**Depends on:** MB-10 · **Estimate:** 1d · **Labels:** QA

---

#### `[MB-16]` v1.0.0 release

**Description:**  
README, getting-started, Packagist publish for core + adapter packages, git tag `v1.0.0`, CHANGELOG.

**Depends on:** MB-13 → MB-15 · **Estimate:** 0.5d · **Labels:** Docs

---

### Board summary (v1.0)

| List | Cards | Days |
|------|-------|------|
| Foundation | MB-01 → MB-03 | 3.5 |
| Engines | MB-04 → MB-07 | 5.5 |
| Compression | MB-08 | 1.5 |
| Runners + local | MB-09 → MB-10 | 3 |
| Adapter packages | MB-11 → MB-12 | 3 |
| CLI + release | MB-13 → MB-16 | 4.5 |
| **v1.0 total** | **MB-01 → MB-16** | **~11d** |

**MVP slice (smallest shippable):** MB-01 → MB-10, MB-14, MB-16 (~**9d**) — core + local storage only, no GCS/S3 packages yet.

---

## Post-v1 roadmap (brief)

| Version | Focus | Est. |
|---------|-------|------|
| **v1.1** | FTP/SFTP adapter package, streaming uploads for large dumps | +4d |
| **v1.2** | PITR helpers (MySQL binlog, PostgreSQL WAL command builders) | +5d |
| **v1.3** | Optional encryption at rest (libsodium, envelope with caller-supplied key) | +4d |
| **v2.0** | Parallel table dumps, incremental backups (engine-dependent) | TBD |

---

## Success criteria for v1.0

1. `composer require monkeysbackup/monkeysbackup` installs with **no** transitive runtime dependencies.
2. A MySQL backup to local disk and restore works via CLI on a clean Ubuntu VM with only `php8.4`, `mysqldump`, and `mysql` installed.
3. GCS and S3 adapters install separately and work via `StorageAdapterFactory::fromConfig()`.
4. All engine command construction is covered by unit tests without live DBs.
5. Documentation is enough for a new contributor to add a sixth engine in one PR.

---

## Open questions (resolve in MB-01)

| Question | Default for v1 |
|----------|----------------|
| Monorepo vs split GitHub repos? | Monorepo `monkeysbackup/monkeysbackup` with `packages/storage-*` |
| Custom format default for PostgreSQL? | Plain SQL (`pg_dump` default); `-Fc` opt-in |
| Redis restore strategy? | Document-only in v1; copy RDB to configured path |
| Adapter auto-registration? | Composer autoload files in adapter packages call `AdapterRegistrar::boot()` |

---

*This document describes MonkeysBackup as a standalone open source project. Consumers (hosting panels, cron jobs, internal platforms) integrate via Composer and the factory — the core does not assume any particular host application.*
