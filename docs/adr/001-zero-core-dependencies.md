# ADR 001: Zero Core Dependencies

## Status

Accepted

## Context

MonkeysLegion Backup is a PHP library and CLI designed to be embedded in various environments, including cron jobs, queue workers, deployment scripts, hosting panels, and different web frameworks (e.g., Laravel, Symfony, or custom legacy systems). 

If the core library pulled in common packages such as `symfony/process`, `psr/log`, or cloud SDKs (`aws/aws-sdk-php`, `google/cloud-storage`), it would likely cause dependency version conflicts with the host applications integrating the library. For example, a host application using an older or newer version of Symfony components or the AWS SDK could not co-exist with MonkeysLegion Backup.

We need a way to build a robust, pluggable backup and restore utility that remains completely decoupled from third-party vendor libraries in its core package.

## Decision

1. **Zero Runtime Dependencies in Core**: The core package (`monkeyscloud/monkeyslegion-backup`) will have **zero** runtime dependencies under Composer's `require` section, except for PHP itself (`^8.4`) and required native PHP extensions (like `ext-json`).
2. **Subprocess Management via `proc_open`**: Instead of relying on `symfony/process`, we will build a custom process wrapper (`ProcessRunner`) using PHP's native `proc_open`, `proc_close`, and stream functions.
3. **Internal Interfaces**: We will define simple, minimal internal interfaces for logging (`LoggerInterface`) and storage (`StorageAdapterInterface`) within the core namespace, rather than depending on external standards like PSR-3.
4. **Decoupled Pluggable Storage Adapters**: Any storage adapters requiring third-party SDKs (such as AWS S3 or Google Cloud Storage) will be developed and published as separate, optional packages (e.g., `monkeysbackup/storage-s3`). The core library will dynamically resolve them at runtime via a factory pattern.

## Consequences

- **Pros**:
  - Extremely lightweight footprint with no transitive dependencies.
  - Zero risk of dependency version conflicts when embedded in host applications.
  - Modular and clean architecture with clear separation of concerns.
- **Cons**:
  - We must implement and maintain custom process orchestration (`ProcessRunner`), which requires careful handling of timeouts, buffer blocking, and secure arguments passing.
  - Slightly more setup work to publish and manage multiple packages in a monorepo or split repos.
