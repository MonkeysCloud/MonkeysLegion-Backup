# MonkeysLegion Backup — Task Board

This board tracks the implementation tasks for **MonkeysLegion-Backup** (v1.0.0).

> [!NOTE]
> All 16 tasks completed. Ready for v1.0.0 release tag.

## 📊 Progress Summary

- **Total Progress:** 100% (16/16 Tasks)
- **Estimated Effort Remaining:** None — tag when ready

| Category | Tasks Completed | Progress |
| :--- | :---: | :---: |
| **Foundation** | 3 / 3 | 100% |
| **Engines** | 4 / 4 | 100% |
| **Compression & Process** | 1 / 1 | 100% |
| **Runners & Local Storage** | 2 / 2 | 100% |
| **Adapter Packages** | 2 / 2 | 100% |
| **CLI & Release** | 4 / 4 | 100% |

---

## 🗂️ Detailed Task Board

### 1. Foundation

- [x] **`[MB-01]` Repository scaffold**
  - **Description:** Single repo with PSR-4 auto-loading, MIT license, PHP 8.4, PHPStan, PHPUnit, and GitHub Actions CI.
  - **Acceptance Criteria:**
    - [x] `composer test` passes on an empty suite.
    - [x] ADR `001-zero-core-dependencies.md` created and committed.
  - **Status:** ✅ Completed

- [x] **`[MB-02]` Contracts & value objects**
  - **Description:** Create core interfaces (`EngineInterface`, `StorageAdapterInterface`, `CompressorInterface`, `LoggerInterface`) and immutable value objects (`DumpOptions`, `RestoreOptions`, `BackupArtifact`, `BackupMetadata`, `StorageConfig`, `BackupResult`).
  - **Acceptance Criteria:**
    - [x] All value objects are immutable.
    - [x] `StorageConfig::fromArray()` and `fromJsonFile()` implemented.
  - **Depends on:** `[MB-01]`
  - **Status:** ✅ Completed

- [x] **`[MB-03]` StorageAdapterFactory**
  - **Description:** Implement factory that resolves `local`, `gcs`, and `s3` adapter classes. Check and throw `StorageAdapterNotFoundException` if optional packages are missing. Support custom register.
  - **Acceptance Criteria:**
    - [x] Unit test verifies clear exception when package for `gcs`/`s3` is missing.
    - [x] Unit test verifies custom adapter registration.
  - **Depends on:** `[MB-02]`
  - **Status:** ✅ Completed

---

### 2. Supported Engines

- [x] **`[MB-04]` MySQL engine**
  - **Description:** Implement MySQL engine wrapping `mysqldump` and `mysql` via `ProcessRunner`.
  - **Acceptance Criteria:**
    - [x] Supports `--single-transaction`, `--routines`, and `--triggers`.
    - [x] Unit tests assert argv array construction without running actual shell commands.
  - **Depends on:** `[MB-02]`
  - **Status:** ✅ Completed

- [x] **`[MB-05]` PostgreSQL engine**
  - **Description:** Implement PostgreSQL engine wrapping `pg_dump` and `psql` / `pg_restore`.
  - **Acceptance Criteria:**
    - [x] Supports plain SQL and custom (`-Fc`) formats.
  - **Depends on:** `[MB-02]`
  - **Status:** ✅ Completed

- [x] **`[MB-06]` MongoDB, Redis, SQLite engines**
  - **Description:** Implement MongoDB (`mongodump --archive`), Redis (`redis-cli --rdb`), and SQLite (file copy) engines.
  - **Acceptance Criteria:**
    - [x] Document Redis restore caveats in docblocks.
    - [x] Redis supports both passwordless (default user) and ACL-authenticated users via `REDISCLI_AUTH`.
    - [x] SQLite supports file-based databases and in-memory databases via `SQLite3` and `PDO` connections.
    - [x] MongoDB supports full archive dump/restore round-trip.
  - **Depends on:** `[MB-02]`
  - **Status:** ✅ Completed

- [x] **`[MB-07]` EngineRegistry**
  - **Description:** Registry class providing access to the five built-in engines.
  - **Acceptance Criteria:**
    - [x] `EngineRegistry::default()` registers all five engines.
    - [x] Throws `EngineException` for unknown engines.
    - [x] `register(string $name, ...)` accepts any string key, not just built-in enum values.
  - **Depends on:** `[MB-04]`, `[MB-05]`, `[MB-06]`
  - **Status:** ✅ Completed

---

### 3. Compression & Process

- [x] **`[MB-08]` GzipCompressor + ProcessRunner**
  - **Description:** Gzip compression utility and secure `ProcessRunner` using PHP `proc_open`.
  - **Acceptance Criteria:**
    - [x] `ProcessRunner` handles timeouts, env injection, stderr merge, and secret redaction.
    - [x] No shell injection vulnerability (argv array only).
  - **Depends on:** `[MB-02]`
  - **Status:** ✅ Completed

---

### 4. Runners & Local Storage

- [x] **`[MB-09]` LocalStorageAdapter**
  - **Description:** Core local storage adapter implementing `StorageAdapterInterface`.
  - **Acceptance Criteria:**
    - [x] Creates nested directories automatically.
    - [x] Performs atomic writes using temp file + rename.
  - **Depends on:** `[MB-03]`
  - **Status:** ✅ Completed

- [x] **`[MB-10]` BackupRunner & RestoreRunner**
  - **Description:** Orchestrate dump → compress → checksum → metadata sidecar → upload, and vice versa.
  - **Acceptance Criteria:**
    - [x] Never log passwords or leak credentials.
    - [x] Computes and verifies SHA-256 checksums.
  - **Depends on:** `[MB-07]`, `[MB-08]`, `[MB-09]`
  - **Status:** ✅ Completed

---

### 5. Optional Adapter Packages

- [x] **`[MB-11]` Package `storage-gcs`**
  - **Description:** Optional sub-package for GCS storage driver.
  - **Acceptance Criteria:**
    - [x] Integrates with Google Cloud Storage SDK (`google/cloud-storage`).
    - [x] Integration tested against `fake-gcs-server` via Docker (`docker-compose.testing.yml`).
    - [x] Run with: `composer test:gcs` (requires container up).
  - **Depends on:** `[MB-03]`
  - **Status:** ✅ Completed

- [x] **`[MB-12]` Package `storage-s3`**
  - **Description:** Optional sub-package for S3 storage driver.
  - **Acceptance Criteria:**
    - [x] Integrates with AWS S3 SDK (`aws/aws-sdk-php`).
    - [x] Supports custom endpoint for MinIO compatibility.
    - [x] Integration tested against MinIO via Docker (`docker-compose.testing.yml`).
    - [x] Run with: `composer test:s3` (requires container up).
  - **Depends on:** `[MB-03]`
  - **Status:** ✅ Completed

---

### 6. CLI & Quality Assurance

- [x] **`[MB-13]` CLI `monkeys-backup`**
  - **Description:** Pure PHP argv command line interface (no external CLI framework dependency).
  - **Acceptance Criteria:**
    - [x] Commands: `engines`, `dump`, `restore`, `list`.
    - [x] Supports `--dry-run`, `--config`, and environment variables.
  - **Depends on:** `[MB-10]`
  - **Status:** ✅ Completed

- [x] **`[MB-14]` Unit test suite**
  - **Description:** Setup and complete coverage for engines, factory, compressor, and runners using mocks.
  - **Acceptance Criteria:**
    - [x] PHPUnit runs successfully with high coverage.
    - [x] PHPStan runs at Level 8 without issues.
  - **Depends on:** `[MB-10]`
  - **Status:** ✅ Completed

- [x] **`[MB-15]` Integration tests**
  - **Description:** Setup Docker Compose CI to verify real dump & restore operations with live database instances.
  - **Acceptance Criteria:**
    - [x] CI pipeline validates end-to-end flow with MySQL/Postgres.
    - [x] `BackupRunnerIntegrationTest` — full pipeline (dump → store → restore → verify) for MySQL and Postgres.
    - [x] MySQL/Postgres services added to `docker-compose.testing.yml`.
    - [x] `composer test:mysql`, `test:pgsql`, `test:db` scripts added.
  - **Depends on:** `[MB-10]`
  - **Status:** ✅ Completed

- [x] **`[MB-16]` Release v1.0.0**
  - **Description:** Complete documentation, changelog, Packagist integration, and release tagging.
  - **Acceptance Criteria:**
    - [x] `CHANGELOG.md` authored covering all 16 milestones.
    - [x] `composer.json` enriched with `description`, `keywords`, `authors`, and `homepage`.
    - [ ] Tag `v1.0.0` pushed to origin (deferred — run `git tag v1.0.0 && git push origin v1.0.0`).
  - **Depends on:** `[MB-13]`, `[MB-14]`, `[MB-15]`
  - **Status:** ✅ Completed (tag deferred)
