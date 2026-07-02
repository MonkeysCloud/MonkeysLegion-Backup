<?php

declare(strict_types=1);

namespace MonkeysLegion\Backup\Tests\Integration;

use MonkeysLegion\Backup\Compressor\GzipCompressor;
use MonkeysLegion\Backup\Engine\EngineRegistry;
use MonkeysLegion\Backup\Runner\BackupRunner;
use MonkeysLegion\Backup\Runner\RestoreRunner;
use MonkeysLegion\Backup\Storage\StorageAdapterFactory;
use MonkeysLegion\Backup\ValueObject\DumpOptions;
use MonkeysLegion\Backup\ValueObject\RestoreOptions;
use MonkeysLegion\Backup\ValueObject\StorageConfig;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * End-to-end pipeline integration tests — MB-15.
 *
 * Runs BackupRunner → LocalStorageAdapter → RestoreRunner against live MySQL
 * and Postgres containers (docker-compose.testing.yml).
 *
 * Prerequisites (managed via docker-compose.testing.yml):
 *   docker compose -f docker-compose.testing.yml up -d --wait
 *
 * Run:
 *   composer test:mysql   — MySQL pipeline only
 *   composer test:pgsql   — Postgres pipeline only
 *   composer test:db      — all database engine + pipeline tests
 */
final class BackupRunnerIntegrationTest extends TestCase
{
    use IntegrationEnv;

    private string $storageRoot;

    protected function setUp(): void
    {
        $this->storageRoot = \sys_get_temp_dir() . '/mb_pipeline_' . \getmypid();
        \mkdir($this->storageRoot, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->storageRoot);
    }

    // -------------------------------------------------------------------------
    // MySQL — full pipeline
    // -------------------------------------------------------------------------

    #[Group('mysql')]
    #[Group('db')]
    public function testMysqlFullPipeline(): void
    {
        $env = $this->loadEnv();
        $host = $env['MYSQL_HOST'] ?? \getenv('MYSQL_HOST') ?: '127.0.0.1';
        $port = (int) ($env['MYSQL_PORT'] ?? \getenv('MYSQL_PORT') ?: 3306);
        $this->skipUnlessDockerServiceReachable('MySQL', $host, $port);

        $user     = $env['MYSQL_USER']                  ?? \getenv('MYSQL_USER')           ?: 'root';
        $password = $env['MYSQL_PASSWORD']              ?? \getenv('MYSQL_PASSWORD')        ?: 'secret';
        $dumpDb   = $env['MYSQL_TEST_DB_DUMP']          ?? \getenv('MYSQL_TEST_DB_DUMP')   ?: 'mb_test_dump';
        $restoreDb = $env['MYSQL_TEST_DB_RESTORE']      ?? \getenv('MYSQL_TEST_DB_RESTORE') ?: 'mb_test_restore';

        // Seed source database
        $this->mysqlExec($host, $port, $user, $password, $dumpDb, "
            DROP TABLE IF EXISTS mb_pipeline_users;
            CREATE TABLE mb_pipeline_users (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(100) NOT NULL);
            INSERT INTO mb_pipeline_users (name) VALUES ('Alice'), ('Bob'), ('Charlie');
        ");
        // Clear restore target
        $this->mysqlExec($host, $port, $user, $password, $restoreDb, "
            DROP TABLE IF EXISTS mb_pipeline_users;
        ");

        [$runner, $restorer] = $this->buildPipeline('mysql');

        // 1. Backup
        $dumpOpts = new DumpOptions(
            engine: 'mysql',
            host: $host,
            port: $port,
            user: $user,
            password: $password,
            database: $dumpDb,
            compress: true,
        );
        $result = $runner->run($dumpOpts, "pipeline/mysql_{$dumpDb}.sql.gz");

        $this->assertNotEmpty($result->remoteKey);
        $this->assertGreaterThan(0, $result->sizeBytes);
        $this->assertNotEmpty($result->checksum);

        // 2. Restore
        $restoreOpts = new RestoreOptions(
            engine: 'mysql',
            sourcePath: $result->remoteKey,
            host: $host,
            port: $port,
            user: $user,
            password: $password,
            database: $restoreDb,
        );
        $restorer->run($restoreOpts);

        // 3. Verify data integrity
        $rows  = $this->mysqlFetch($host, $port, $user, $password, $restoreDb, 'SELECT name FROM mb_pipeline_users ORDER BY id');
        $names = \array_column($rows, 'name');
        $this->assertSame(['Alice', 'Bob', 'Charlie'], $names);
    }

    // -------------------------------------------------------------------------
    // Postgres — full pipeline
    // -------------------------------------------------------------------------

    #[Group('pgsql')]
    #[Group('db')]
    public function testPostgresFullPipeline(): void
    {
        $env = $this->loadEnv();
        $host = $env['PGSQL_HOST'] ?? \getenv('PGSQL_HOST') ?: '127.0.0.1';
        $port = (int) ($env['PGSQL_PORT'] ?? \getenv('PGSQL_PORT') ?: 5432);
        $this->skipUnlessDockerServiceReachable('PostgreSQL', $host, $port);

        $user      = $env['PGSQL_USER']                 ?? \getenv('PGSQL_USER')             ?: 'postgres';
        $password  = $env['PGSQL_PASSWORD']             ?? \getenv('PGSQL_PASSWORD')         ?: 'secret';
        $dumpDb    = $env['PGSQL_TEST_DB_DUMP']         ?? \getenv('PGSQL_TEST_DB_DUMP')     ?: 'mb_test_dump';
        $restoreDb = $env['PGSQL_TEST_DB_RESTORE']      ?? \getenv('PGSQL_TEST_DB_RESTORE')  ?: 'mb_test_restore';

        // Seed source database
        $this->pgsqlExec($host, $port, $user, $password, $dumpDb, "
            DROP TABLE IF EXISTS mb_pipeline_posts;
            CREATE TABLE mb_pipeline_posts (id SERIAL PRIMARY KEY, title VARCHAR(100) NOT NULL);
            INSERT INTO mb_pipeline_posts (title) VALUES ('Alpha'), ('Beta'), ('Gamma');
        ");
        // Clear restore target
        $this->pgsqlExec($host, $port, $user, $password, $restoreDb, "
            DROP TABLE IF EXISTS mb_pipeline_posts;
        ");

        [$runner, $restorer] = $this->buildPipeline('pgsql');

        // 1. Backup (plain SQL format for full round-trip via psql)
        $dumpOpts = new DumpOptions(
            engine: 'postgres',
            host: $host,
            port: $port,
            user: $user,
            password: $password,
            database: $dumpDb,
            compress: true,
            customOptions: ['format' => 'plain'],
        );
        $result = $runner->run($dumpOpts, "pipeline/pgsql_{$dumpDb}.sql.gz");

        $this->assertNotEmpty($result->remoteKey);
        $this->assertGreaterThan(0, $result->sizeBytes);
        $this->assertNotEmpty($result->checksum);

        // 2. Restore
        $restoreOpts = new RestoreOptions(
            engine: 'postgres',
            sourcePath: $result->remoteKey,
            host: $host,
            port: $port,
            user: $user,
            password: $password,
            database: $restoreDb,
        );
        $restorer->run($restoreOpts);

        // 3. Verify data integrity
        $rows   = $this->pgsqlFetch($host, $port, $user, $password, $restoreDb, 'SELECT title FROM mb_pipeline_posts ORDER BY id');
        $titles = \array_column($rows, 'title');
        $this->assertSame(['Alpha', 'Beta', 'Gamma'], $titles);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * @return array{BackupRunner, RestoreRunner}
     */
    private function buildPipeline(string $tag): array
    {
        $storageConfig = StorageConfig::fromArray([
            'driver' => 'local',
            'root'   => "{$this->storageRoot}/{$tag}",
        ]);

        $storage    = StorageAdapterFactory::fromConfig($storageConfig);
        $compressor = new GzipCompressor();
        $registry   = EngineRegistry::default();

        $runner   = new BackupRunner($registry, $storage, $compressor);
        $restorer = new RestoreRunner($registry, $storage, $compressor);

        return [$runner, $restorer];
    }

    private function mysqlPdo(string $host, int $port, string $user, string $password, string $database): \PDO
    {
        $dsn = \sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $host, $port, $database);
        return new \PDO($dsn, $user, $password, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
    }

    private function mysqlExec(string $host, int $port, string $user, string $password, string $db, string $sql): void
    {
        $pdo = $this->mysqlPdo($host, $port, $user, $password, $db);
        foreach (\array_filter(\array_map('trim', \explode(';', $sql))) as $stmt) {
            $pdo->exec($stmt);
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function mysqlFetch(string $host, int $port, string $user, string $password, string $db, string $sql): array
    {
        $stmt = $this->mysqlPdo($host, $port, $user, $password, $db)->query($sql);
        /** @var list<array<string, mixed>> */
        return $stmt ? $stmt->fetchAll(\PDO::FETCH_ASSOC) : [];
    }

    private function pgsqlPdo(string $host, int $port, string $user, string $password, string $database): \PDO
    {
        $dsn = \sprintf('pgsql:host=%s;port=%d;dbname=%s', $host, $port, $database);
        return new \PDO($dsn, $user, $password, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
    }

    private function pgsqlExec(string $host, int $port, string $user, string $password, string $db, string $sql): void
    {
        $this->pgsqlPdo($host, $port, $user, $password, $db)->exec($sql);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function pgsqlFetch(string $host, int $port, string $user, string $password, string $db, string $sql): array
    {
        $stmt = $this->pgsqlPdo($host, $port, $user, $password, $db)->query($sql);
        /** @var list<array<string, mixed>> */
        return $stmt ? $stmt->fetchAll(\PDO::FETCH_ASSOC) : [];
    }

    private function removeDir(string $path): void
    {
        if (!\is_dir($path)) {
            return;
        }
        $entries = \array_diff(\scandir($path) ?: [], ['.', '..']);
        foreach ($entries as $entry) {
            $full = "{$path}/{$entry}";
            \is_dir($full) ? $this->removeDir($full) : \unlink($full);
        }
        \rmdir($path);
    }
}
