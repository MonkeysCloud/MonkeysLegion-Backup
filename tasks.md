# MonkeysLegion Backup — Task Board

This board tracks the implementation tasks for **MonkeysLegion-Backup** (v1.0.0).

> [!NOTE]
> This is a greenfield project. Currently, 4 out of 16 tasks are completed.

## 📊 Progress Summary

- **Total Progress:** 25% (4/16 Tasks)
- **Estimated Effort Remaining:** ~7 developer-days

| Category | Tasks Completed | Progress |
| :--- | :---: | :---: |
| **Foundation** | 3 / 3 | 100% |
| **Engines** | 1 / 4 | 25% |
| **Compression & Process** | 0 / 1 | 0% |
| **Runners & Local Storage** | 0 / 2 | 0% |
| **Adapter Packages** | 0 / 2 | 0% |
| **CLI & Release** | 0 / 4 | 0% |

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

- [ ] **`[MB-05]` PostgreSQL engine**
  - **Description:** Implement PostgreSQL engine wrapping `pg_dump` and `psql` / `pg_restore`.
  - **Acceptance Criteria:**
    - [ ] Supports plain SQL and custom (`-Fc`) formats.
  - **Depends on:** `[MB-02]`
  - **Status:** ⏳ Pending

- [ ] **`[MB-06]` MongoDB, Redis, SQLite engines**
  - **Description:** Implement MongoDB (`mongodump --archive`), Redis (`redis-cli --rdb`), and SQLite (file copy) engines.
  - **Acceptance Criteria:**
    - [ ] Document Redis restore caveats in docblocks.
  - **Depends on:** `[MB-02]`
  - **Status:** ⏳ Pending

- [ ] **`[MB-07]` EngineRegistry**
  - **Description:** Registry class providing access to the five built-in engines.
  - **Acceptance Criteria:**
    - [ ] `EngineRegistry::default()` registers all five engines.
    - [ ] Throws `EngineException` for unknown engines.
  - **Depends on:** `[MB-04]`, `[MB-05]`, `[MB-06]`
  - **Status:** ⏳ Pending

---

### 3. Compression & Process

- [ ] **`[MB-08]` GzipCompressor + ProcessRunner**
  - **Description:** Gzip compression utility and secure `ProcessRunner` using PHP `proc_open`.
  - **Acceptance Criteria:**
    - [ ] `ProcessRunner` handles timeouts, env injection, stderr merge, and secret redaction.
    - [ ] No shell injection vulnerability (argv array only).
  - **Depends on:** `[MB-02]`
  - **Status:** ⏳ Pending

---

### 4. Runners & Local Storage

- [ ] **`[MB-09]` LocalStorageAdapter**
  - **Description:** Core local storage adapter implementing `StorageAdapterInterface`.
  - **Acceptance Criteria:**
    - [ ] Creates nested directories automatically.
    - [ ] Performs atomic writes using temp file + rename.
  - **Depends on:** `[MB-03]`
  - **Status:** ⏳ Pending

- [ ] **`[MB-10]` BackupRunner & RestoreRunner**
  - **Description:** Orchestrate dump → compress → checksum → metadata sidecar → upload, and vice versa.
  - **Acceptance Criteria:**
    - [ ] Never log passwords or leak credentials.
    - [ ] Computes and verifies SHA-256 checksums.
  - **Depends on:** `[MB-07]`, `[MB-08]`, `[MB-09]`
  - **Status:** ⏳ Pending

---

### 5. Optional Adapter Packages

- [ ] **`[MB-11]` Package `storage-gcs`**
  - **Description:** Optional sub-package for GCS storage driver.
  - **Acceptance Criteria:**
    - [ ] Integrates with Google Cloud Storage SDK.
  - **Depends on:** `[MB-03]`
  - **Status:** ⏳ Pending

- [ ] **`[MB-12]` Package `storage-s3`**
  - **Description:** Optional sub-package for S3 storage driver.
  - **Acceptance Criteria:**
    - [ ] Integrates with AWS S3 SDK.
    - [ ] Supports custom endpoint for MinIO compatibility.
  - **Depends on:** `[MB-03]`
  - **Status:** ⏳ Pending

---

### 6. CLI & Quality Assurance

- [ ] **`[MB-13]` CLI `monkeys-backup`**
  - **Description:** Pure PHP argv command line interface (no external CLI framework dependency).
  - **Acceptance Criteria:**
    - [ ] Commands: `engines`, `dump`, `restore`, `list`.
    - [ ] Supports `--dry-run`, `--config`, and environment variables.
  - **Depends on:** `[MB-10]`
  - **Status:** ⏳ Pending

- [ ] **`[MB-14]` Unit test suite**
  - **Description:** Setup and complete coverage for engines, factory, compressor, and runners using mocks.
  - **Acceptance Criteria:**
    - [ ] PHPUnit runs successfully with high coverage.
    - [ ] PHPStan runs at Level 8 without issues.
  - **Depends on:** `[MB-10]`
  - **Status:** ⏳ Pending

- [ ] **`[MB-15]` Integration tests**
  - **Description:** Setup Docker Compose CI to verify real dump & restore operations with live database instances.
  - **Acceptance Criteria:**
    - [ ] CI pipeline validates end-to-end flow with MySQL/Postgres.
  - **Depends on:** `[MB-10]`
  - **Status:** ⏳ Pending

- [ ] **`[MB-16]` Release v1.0.0**
  - **Description:** Complete documentation, changelog, Packagist integration, and release tagging.
  - **Acceptance Criteria:**
    - [ ] Tag `v1.0.0` pushed and documentation complete.
  - **Depends on:** `[MB-13]`, `[MB-14]`, `[MB-15]`
  - **Status:** ⏳ Pending
