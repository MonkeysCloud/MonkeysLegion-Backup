<?php

declare(strict_types=1);

namespace MonkeysLegion\Backup\Tests\Integration;

use MonkeysLegion\Backup\Engine\MysqlEngine;
use MonkeysLegion\Backup\ValueObject\DumpOptions;
use MonkeysLegion\Backup\ValueObject\RestoreOptions;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Integration test for MysqlEngine against a live MySQL instance.
 *
 * Prerequisites (managed via docker-compose.testing.yml):
 *   docker compose -f docker-compose.testing.yml up -d --wait
 *
 * Run only this group:
 *   vendor/bin/phpunit --group mysql
 */
#[Group('mysql')]
#[Group('db')]
final class MysqlEngineIntegrationTest extends TestCase
{
    use IntegrationEnv;

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

        $this->host     = $env['MYSQL_HOST']           ?? '127.0.0.1';
        $this->port     = (int)($env['MYSQL_PORT']     ?? 3306);
        $this->user     = $env['MYSQL_USER']           ?? 'root';
        $this->password = $env['MYSQL_PASSWORD']       ?? 'secret';
        $this->dumpDb   = $env['MYSQL_TEST_DB_DUMP']   ?? 'mb_test_dump';
        $this->restoreDb= $env['MYSQL_TEST_DB_RESTORE'] ?? 'mb_test_restore';

        $this->skipUnlessDockerServiceReachable('MySQL', $this->host, $this->port);

        $this->engine = new MysqlEngine();

        $this->execSql($this->dumpDb, "
            DROP TABLE IF EXISTS mb_test_users;
            CREATE TABLE mb_test_users (
                id   INT AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(100) NOT NULL
            );
            INSERT INTO mb_test_users (name) VALUES ('Alice'), ('Bob'), ('Charlie');
        ");

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

        $content = \file_get_contents($artifact->localPath);
        $this->assertIsString($content);
        $this->assertStringContainsString('mb_test_users', $content);

        \unlink($artifact->localPath);
    }

    public function testDumpAndRestoreRoundTrip(): void
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

        $this->engine->restore(new RestoreOptions(
            engine: 'mysql',
            sourcePath: $artifact->localPath,
            host: $this->host,
            port: $this->port,
            user: $this->user,
            password: $this->password,
            database: $this->restoreDb,
        ));

        $rows = $this->fetchAll($this->restoreDb, 'SELECT name FROM mb_test_users ORDER BY id');
        $names = \array_column($rows, 'name');

        $this->assertSame(['Alice', 'Bob', 'Charlie'], $names);

        \unlink($artifact->localPath);
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
