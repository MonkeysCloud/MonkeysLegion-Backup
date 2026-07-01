<?php

declare(strict_types=1);

namespace MonkeysLegion\Backup\Tests\Integration;

use MonkeysLegion\Backup\Engine\MysqlEngine;
use MonkeysLegion\Backup\ValueObject\DumpOptions;
use MonkeysLegion\Backup\ValueObject\RestoreOptions;
use PHPUnit\Framework\TestCase;

/**
 * Integration tests for MysqlEngine.
 *
 * Requires live MySQL access. Credentials are loaded from .env via setUp().
 * Only runs if MYSQL_HOST env variable is present.
 *
 * The test creates/uses dedicated test databases (mb_test_dump, mb_test_restore)
 * and never touches other databases.
 */
final class MysqlEngineIntegrationTest extends TestCase
{
    private string $host;
    private int    $port;
    private string $user;
    private string $password;
    private string $dumpDb;
    private string $restoreDb;
    private MysqlEngine $engine;

    protected function setUp(): void
    {
        $env = $this->loadEnv();

        $this->host     = $env['MYSQL_HOST']          ?? '127.0.0.1';
        $this->port     = (int)($env['MYSQL_PORT']    ?? 3306);
        $this->user     = $env['MYSQL_USER']          ?? 'root';
        $this->password = $env['MYSQL_PASSWORD']      ?? '';
        $this->dumpDb   = $env['MYSQL_TEST_DB_DUMP']  ?? 'mb_test_dump';
        $this->restoreDb= $env['MYSQL_TEST_DB_RESTORE']?? 'mb_test_restore';

        if ($this->host === '') {
            $this->markTestSkipped('MYSQL_HOST not set — skipping integration tests.');
        }

        $this->engine = new MysqlEngine();

        // Seed the dump database with a small test table
        $this->execSql($this->dumpDb, "
            DROP TABLE IF EXISTS mb_test_users;
            CREATE TABLE mb_test_users (
                id   INT AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(100) NOT NULL
            );
            INSERT INTO mb_test_users (name) VALUES ('Alice'), ('Bob'), ('Charlie');
        ");

        // Ensure the restore database is empty for a clean run
        $this->execSql($this->restoreDb, 'DROP TABLE IF EXISTS mb_test_users;');
    }

    public function testDumpProducesReadableFile(): void
    {
        $artifact = $this->engine->dump(new DumpOptions(
            engine: 'mysql',
            host: $this->host,
            port: $this->port,
            user: $this->user,
            password: $this->password,
            database: $this->dumpDb,
        ));

        $this->assertFileExists($artifact->localPath);
        $this->assertGreaterThan(0, \filesize($artifact->localPath));

        // SQL dump must contain the table DDL
        $content = \file_get_contents($artifact->localPath);
        $this->assertIsString($content);
        $this->assertStringContainsString('mb_test_users', $content);

        \unlink($artifact->localPath);
    }

    public function testDumpAndRestoreRoundTrip(): void
    {
        // 1. Dump source db
        $artifact = $this->engine->dump(new DumpOptions(
            engine: 'mysql',
            host: $this->host,
            port: $this->port,
            user: $this->user,
            password: $this->password,
            database: $this->dumpDb,
        ));

        $this->assertFileExists($artifact->localPath);

        // 2. Restore into target db
        $this->engine->restore(new RestoreOptions(
            engine: 'mysql',
            sourcePath: $artifact->localPath,
            host: $this->host,
            port: $this->port,
            user: $this->user,
            password: $this->password,
            database: $this->restoreDb,
        ));

        // 3. Assert restored rows
        $rows = $this->fetchAll($this->restoreDb, 'SELECT name FROM mb_test_users ORDER BY id');
        $names = \array_column($rows, 'name');

        $this->assertSame(['Alice', 'Bob', 'Charlie'], $names);

        \unlink($artifact->localPath);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Load key=value pairs from the project-root .env file.
     *
     * @return array<string, string>
     */
    private function loadEnv(): array
    {
        $envFile = \dirname(__DIR__, 2) . '/.env';
        if (!\is_readable($envFile)) {
            return [];
        }

        $result = [];
        $lines  = \file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return [];
        }

        foreach ($lines as $line) {
            $line = \trim($line);
            if ($line === '' || $line[0] === '#') {
                continue;
            }
            [$key, $val] = \explode('=', $line, 2) + ['', ''];
            $result[\trim($key)] = \trim($val);
        }

        return $result;
    }

    private function getPdo(string $database): \PDO
    {
        $dsn = \sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            $this->host,
            $this->port,
            $database
        );
        return new \PDO($dsn, $this->user, $this->password, [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
        ]);
    }

    private function execSql(string $database, string $sql): void
    {
        $pdo = $this->getPdo($database);
        foreach (\array_filter(\array_map('trim', \explode(';', $sql))) as $stmt) {
            $pdo->exec($stmt);
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fetchAll(string $database, string $sql): array
    {
        $pdo  = $this->getPdo($database);
        $stmt = $pdo->query($sql);
        if ($stmt === false) {
            return [];
        }
        /** @var list<array<string, mixed>> */
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }
}
