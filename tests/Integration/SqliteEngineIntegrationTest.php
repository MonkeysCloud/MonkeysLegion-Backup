<?php

declare(strict_types=1);

namespace MonkeysLegion\Backup\Tests\Integration;

use MonkeysLegion\Backup\Engine\SqliteEngine;
use MonkeysLegion\Backup\ValueObject\DumpOptions;
use MonkeysLegion\Backup\ValueObject\RestoreOptions;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Integration test for SqliteEngine (file-based; no Docker required).
 *
 * Run only this group:
 *   vendor/bin/phpunit --group sqlite
 */
#[Group('sqlite')]
final class SqliteEngineIntegrationTest extends TestCase
{
    private SqliteEngine $engine;
    private string $tempDir;

    protected function setUp(): void
    {
        $this->engine = new SqliteEngine();
        $this->tempDir = \sys_get_temp_dir() . '/mb_sqlite_test';
        if (!\is_dir($this->tempDir)) {
            \mkdir($this->tempDir, 0755, true);
        }
    }

    protected function tearDown(): void
    {
        if (\is_dir($this->tempDir)) {
            $files = \glob("{$this->tempDir}/*");
            if ($files !== false) {
                foreach ($files as $file) {
                    if (\is_file($file)) {
                        \unlink($file);
                    }
                }
            }
            \rmdir($this->tempDir);
        }
    }

    public function testFileDbBackupAndRestore(): void
    {
        $dbPath = "{$this->tempDir}/source.db";
        $restorePath = "{$this->tempDir}/restore.db";

        // Create source database and populate it
        $db = new \SQLite3($dbPath);
        $db->exec("CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT);");
        $db->exec("INSERT INTO users (name) VALUES ('Alice'), ('Bob');");
        $db->close();

        // 1. Dump database
        $artifact = $this->engine->dump(new DumpOptions(
            engine: 'sqlite',
            database: $dbPath
        ));

        $this->assertFileExists($artifact->localPath);
        $this->assertGreaterThan(0, \filesize($artifact->localPath));

        // 2. Restore database
        $this->engine->restore(new RestoreOptions(
            engine: 'sqlite',
            sourcePath: $artifact->localPath,
            database: $restorePath
        ));

        $this->assertFileExists($restorePath);

        // 3. Verify restored data
        $restoredDb = new \SQLite3($restorePath);
        $result = $restoredDb->query("SELECT name FROM users ORDER BY id;");
        $names = [];
        if ($result !== false) {
            while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
                $names[] = $row['name'];
            }
        }
        $restoredDb->close();

        $this->assertSame(['Alice', 'Bob'], $names);

        \unlink($artifact->localPath);
    }

    public function testMemoryDbBackupAndRestoreWithSQLite3Connection(): void
    {
        // 1. Create source memory DB with connection
        $sourceConn = new \SQLite3(':memory:');
        $sourceConn->exec("CREATE TABLE settings (key TEXT, val TEXT);");
        $sourceConn->exec("INSERT INTO settings (key, val) VALUES ('theme', 'dark');");

        // 2. Dump from memory DB connection
        $artifact = $this->engine->dump(new DumpOptions(
            engine: 'sqlite',
            database: ':memory:',
            customOptions: ['connection' => $sourceConn]
        ));

        $this->assertFileExists($artifact->localPath);
        $sourceConn->close();

        // 3. Create destination memory DB
        $destConn = new \SQLite3(':memory:');

        // 4. Restore into destination memory DB connection
        $this->engine->restore(new RestoreOptions(
            engine: 'sqlite',
            sourcePath: $artifact->localPath,
            database: ':memory:',
            customOptions: ['connection' => $destConn]
        ));

        // 5. Verify restored data
        $result = $destConn->query("SELECT val FROM settings WHERE key='theme';");
        $val = null;
        if ($result !== false) {
            $row = $result->fetchArray(SQLITE3_ASSOC);
            if ($row !== false) {
                $val = $row['val'];
            }
        }
        $destConn->close();

        $this->assertSame('dark', $val);

        \unlink($artifact->localPath);
    }

    public function testMemoryDbBackupAndRestoreWithPdoConnection(): void
    {
        // 1. Create source memory DB with PDO
        $sourcePdo = new \PDO('sqlite::memory:');
        $sourcePdo->exec("CREATE TABLE settings (key TEXT, val TEXT);");
        $sourcePdo->exec("INSERT INTO settings (key, val) VALUES ('font', 'monospace');");

        // 2. Dump from memory DB connection (uses VACUUM INTO internally)
        $artifact = $this->engine->dump(new DumpOptions(
            engine: 'sqlite',
            database: ':memory:',
            customOptions: ['connection' => $sourcePdo]
        ));

        $this->assertFileExists($artifact->localPath);

        // 3. Create destination memory DB with PDO
        $destPdo = new \PDO('sqlite::memory:');

        // 4. Restore into destination memory DB connection
        $this->engine->restore(new RestoreOptions(
            engine: 'sqlite',
            sourcePath: $artifact->localPath,
            database: ':memory:',
            customOptions: ['connection' => $destPdo]
        ));

        // 5. Verify restored data
        $stmt = $destPdo->query("SELECT val FROM settings WHERE key='font';");
        $val = null;
        if ($stmt !== false) {
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            if ($row !== false) {
                $val = $row['val'];
            }
        }

        $this->assertSame('monospace', $val);

        \unlink($artifact->localPath);
    }
}
