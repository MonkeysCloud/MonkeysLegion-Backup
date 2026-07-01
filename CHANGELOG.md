# Changelog

All notable changes to **MonkeysLegion Backup** are documented in this file.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and
this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

---

## [1.0.0] — 2026-07-01

### Added

#### Foundation (MB-01 / MB-02 / MB-03)
- PSR-4 scaffolding, MIT license, PHP 8.4 minimum
- PHPStan level 8 + PHPUnit 13 CI via GitHub Actions
- ADR `001-zero-core-dependencies.md`
- Core interfaces: `EngineInterface`, `StorageAdapterInterface`, `CompressorInterface`, `LoggerInterface`
- Immutable value objects: `DumpOptions`, `RestoreOptions`, `BackupArtifact`, `BackupMetadata`, `StorageConfig`, `BackupResult`
- `StorageAdapterFactory` with custom adapter registration support
- `LocalStorageAdapter` — atomic writes, path-traversal prevention, nested-directory creation

#### Backup Engines (MB-04 – MB-07)
- `MysqlEngine` — `mysqldump` / `mysql`, password via env var, compression support
- `PostgresEngine` — `pg_dump` (plain + custom `-Fc` format), `psql` / `pg_restore`
- `MongodbEngine` — `mongodump` / `mongorestore` with ACL auth
- `RedisEngine` — `redis-cli` BGSAVE-based dump payload, optional password auth
- `SqliteEngine` — filesystem copy via PDO and `sqlite3` CLI
- `EngineRegistry` — named engine lookup, open for custom engine registration via `register()`

#### Compression & Process (MB-08)
- `GzipCompressor` — streaming gzip compress/decompress, enforces `.gz` extension
- `ProcessRunner` — subprocess execution with stdin injection, env-var secret redaction

#### Runners (MB-09 / MB-10)
- `BackupRunner` — orchestrates: engine dump → optional compression → SHA-256 checksum → metadata sidecar → storage upload
- `RestoreRunner` — orchestrates: storage download → checksum validation → optional decompression → engine restore
- JSON metadata sidecar (`.meta`) stored alongside every backup for integrity verification

#### Cloud Storage Adapters (MB-11 / MB-12)
- `GcsStorageAdapter` — Google Cloud Storage, configurable API endpoint (fake-gcs-server compatible)
- `S3StorageAdapter` — AWS S3 / MinIO with path-style endpoint override
- Integration tests for both adapters via Docker Compose (`fake-gcs-server`, MinIO)

#### CLI Interface (MB-13 / MB-14)
- `BaseCommand` — `monkeyslegion-cli` attribute-driven base; `getSafeOption` argv parser; dry-run detection; env-based DB credential resolution; `MonkeysLegion\Logger` bridge (null-safe `?->`)
- `EnginesCommand` (`engines`) — tabular list of registered engines
- `ListCommand` (`list`) — storage listing with `.meta` sidecar filtering
- `DumpCommand` (`dump`) — plan preview, dry-run, full `BackupRunner` orchestration
- `RestoreCommand` (`restore`) — plan preview, dry-run, full `RestoreRunner` orchestration
- `InstallCommand` (`install`) — publish `config/backup.mlc` (default) or `config/backup.php` via `--format`
- `config/backup.mlc` — MLC-syntax configuration file
- `config/backup.php` — Laravel-style PHP configuration file
- `docs/registration_guide.md` — DI container integration guide
- All CLI commands fail-fast if required container services are missing (no silent auto-bootstrap)
- PHPStan level 8 clean; 100 tests / 359+ assertions passing

#### Integration Tests (MB-15)
- `BackupRunnerIntegrationTest` — full pipeline (dump → local storage → restore → row assertion) for MySQL and Postgres
- MySQL 8.4 and Postgres 16 services added to `docker-compose.testing.yml`
- `composer test:mysql`, `composer test:pgsql`, `composer test:db` scripts

---

[1.0.0]: https://github.com/monkeyscloud/MonkeysLegion-Backup/releases/tag/v1.0.0
