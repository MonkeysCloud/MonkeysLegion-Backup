<?php

declare(strict_types=1);

namespace MonkeysLegion\Backup\Tests\Integration;

use MonkeysLegion\Backup\Engine\PostgresEngine;
use MonkeysLegion\Backup\ValueObject\DumpOptions;
use MonkeysLegion\Backup\ValueObject\RestoreOptions;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Integration test for PostgresEngine against a live PostgreSQL instance.
 *
 * Prerequisites (managed via docker-compose.testing.yml):
 *   docker compose -f docker-compose.testing.yml up -d --wait
 *
 * Run only this group:
 *   vendor/bin/phpunit --group pgsql
 */
#[Group('pgsql')]
#[Group('db')]
final class PostgresEngineIntegrationTest extends TestCase
{
    use IntegrationEnv;

    private string $host;
    private int    $port;
    private string $user;
    private string $password;
    private string $dumpDb;
    private string $restoreDb;
    private PostgresEngine $engine;

    protected function setUp(): void
    {
        $env = $this->loadEnv();

        $this->host     = $env['PGSQL_HOST']            ?? '127.0.0.1';
        $this->port     = (int)($env['PGSQL_PORT']      ?? 5432);
        $this->user     = $env['PGSQL_USER']            ?? 'postgres';
        $this->password = $env['PGSQL_PASSWORD']        ?? 'secret';
        $this->dumpDb   = $env['PGSQL_TEST_DB_DUMP']    ?? 'mb_test_dump';
        $this->restoreDb= $env['PGSQL_TEST_DB_RESTORE'] ?? 'mb_test_restore';

        $this->skipUnlessDockerServiceReachable('PostgreSQL', $this->host, $this->port);

        $this->engine = new PostgresEngine();

        $this->execSql($this->dumpDb, "
            DROP TABLE IF EXISTS mb_test_posts;
            CREATE TABLE mb_test_posts (
                id SERIAL PRIMARY KEY,
                title VARCHAR(100) NOT NULL
            );
            INSERT INTO mb_test_posts (title) VALUES ('First Post'), ('Second Post');
        ");

        $this->execSql($this->restoreDb, "
            DROP TABLE IF EXISTS mb_test_posts;
        ");
    }

    public function testPlainDumpAndRestoreRoundTrip(): void
    {
        $artifact = $this->engine->dump(new DumpOptions(
            engine: 'postgres',
            host: $this->host,
            port: $this->port,
            user: $this->user,
            password: $this->password,
            database: $this->dumpDb,
            customOptions: ['format' => 'plain']
        ));

        $this->assertFileExists($artifact->localPath);
        $this->assertStringEndsWith('.sql', $artifact->localPath);

        $this->engine->restore(new RestoreOptions(
            engine: 'postgres',
            sourcePath: $artifact->localPath,
            host: $this->host,
            port: $this->port,
            user: $this->user,
            password: $this->password,
            database: $this->restoreDb
        ));

        $rows = $this->fetchAll($this->restoreDb, 'SELECT title FROM mb_test_posts ORDER BY id');
        $titles = \array_column($rows, 'title');

        $this->assertSame(['First Post', 'Second Post'], $titles);

        \unlink($artifact->localPath);
    }

    public function testCustomFormatDumpAndRestoreRoundTrip(): void
    {
        $artifact = $this->engine->dump(new DumpOptions(
            engine: 'postgres',
            host: $this->host,
            port: $this->port,
            user: $this->user,
            password: $this->password,
            database: $this->dumpDb,
            customOptions: ['format' => 'custom']
        ));

        $this->assertFileExists($artifact->localPath);
        $this->assertStringEndsWith('.dump', $artifact->localPath);

        $this->engine->restore(new RestoreOptions(
            engine: 'postgres',
            sourcePath: $artifact->localPath,
            host: $this->host,
            port: $this->port,
            user: $this->user,
            password: $this->password,
            database: $this->restoreDb,
            customOptions: ['--clean', '--if-exists']
        ));

        $rows = $this->fetchAll($this->restoreDb, 'SELECT title FROM mb_test_posts ORDER BY id');
        $titles = \array_column($rows, 'title');

        $this->assertSame(['First Post', 'Second Post'], $titles);

        \unlink($artifact->localPath);
    }

    private function getPdo(string $database): \PDO
    {
        $dsn = \sprintf(
            'pgsql:host=%s;port=%d;dbname=%s',
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
        $pdo->exec($sql);
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
