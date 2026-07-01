<?php

declare(strict_types=1);

namespace MonkeysLegion\Backup\Tests\Unit;

use MonkeysLegion\Backup\Engine\SqliteEngine;
use MonkeysLegion\Backup\Exception\EngineException;
use MonkeysLegion\Backup\ValueObject\DumpOptions;
use MonkeysLegion\Backup\ValueObject\RestoreOptions;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;

#[AllowMockObjectsWithoutExpectations]
final class SqliteEngineTest extends TestCase
{
    private SqliteEngine $engine;
    private string $tempDir;

    protected function setUp(): void
    {
        $this->engine  = new SqliteEngine();
        $this->tempDir = \sys_get_temp_dir() . '/mb_sqlite_test_' . \uniqid();
        \mkdir($this->tempDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tempDir);
    }

    public function testName(): void
    {
        $this->assertSame('sqlite', $this->engine->name());
    }

    public function testSupportsKnownFeatures(): void
    {
        $this->assertTrue($this->engine->supports('compression'));
        $this->assertFalse($this->engine->supports('unknown'));
    }

    // -------------------------------------------------------------------------
    // dump — file-based
    // -------------------------------------------------------------------------

    public function testDumpCopiesExistingDbFile(): void
    {
        $dbFile = "{$this->tempDir}/source.db";
        // Create a minimal valid SQLite database
        $db = new \SQLite3($dbFile);
        $db->exec('CREATE TABLE t (id INTEGER PRIMARY KEY)');
        $db->exec("INSERT INTO t VALUES (1)");
        $db->close();

        $artifact = $this->engine->dump(new DumpOptions(
            engine: 'sqlite',
            database: $dbFile,
        ));

        $this->assertFileExists($artifact->localPath);
        $this->assertGreaterThan(0, \filesize($artifact->localPath));
        $this->assertSame('sqlite', $artifact->engine);
        $this->assertSame($dbFile, $artifact->database);

        \unlink($artifact->localPath);
    }

    public function testDumpThrowsWhenDatabaseIsEmpty(): void
    {
        $this->expectException(EngineException::class);
        $this->engine->dump(new DumpOptions(engine: 'sqlite', database: ''));
    }

    public function testDumpThrowsWhenFileNotFound(): void
    {
        $this->expectException(EngineException::class);
        $this->engine->dump(new DumpOptions(engine: 'sqlite', database: '/nonexistent/path/db.sqlite'));
    }

    public function testDumpInMemoryViaSQLite3(): void
    {
        $conn = new \SQLite3(':memory:');
        $conn->exec('CREATE TABLE test (x TEXT)');
        $conn->exec("INSERT INTO test VALUES ('hello')");

        $artifact = $this->engine->dump(new DumpOptions(
            engine: 'sqlite',
            database: ':memory:',
            customOptions: ['connection' => $conn],
        ));

        $this->assertFileExists($artifact->localPath);
        $this->assertGreaterThan(0, \filesize($artifact->localPath));

        $conn->close();
        \unlink($artifact->localPath);
    }

    public function testDumpInMemoryViaPDO(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->exec('CREATE TABLE items (id INTEGER PRIMARY KEY, name TEXT)');
        $pdo->exec("INSERT INTO items VALUES (1, 'alpha')");

        $artifact = $this->engine->dump(new DumpOptions(
            engine: 'sqlite',
            database: 'file::memory:?cache=shared',
            customOptions: ['connection' => $pdo],
        ));

        $this->assertFileExists($artifact->localPath);
        \unlink($artifact->localPath);
    }

    // -------------------------------------------------------------------------
    // restore — file-based
    // -------------------------------------------------------------------------

    public function testRestoreFileDumpRoundTrip(): void
    {
        $dbFile = "{$this->tempDir}/original.db";
        $db     = new \SQLite3($dbFile);
        $db->exec('CREATE TABLE data (v TEXT)');
        $db->exec("INSERT INTO data VALUES ('monkeys')");
        $db->close();

        // Dump
        $artifact = $this->engine->dump(new DumpOptions(engine: 'sqlite', database: $dbFile));

        // Restore to new path
        $restorePath = "{$this->tempDir}/restored.db";
        $this->engine->restore(new RestoreOptions(
            engine: 'sqlite',
            sourcePath: $artifact->localPath,
            database: $restorePath,
        ));

        $this->assertFileExists($restorePath);
        $restored = new \SQLite3($restorePath);
        $row      = $restored->querySingle('SELECT v FROM data', true);
        $restored->close();

        $this->assertSame(['v' => 'monkeys'], $row);
        \unlink($artifact->localPath);
    }

    public function testRestoreThrowsWhenDatabaseIsEmpty(): void
    {
        $tmp = "{$this->tempDir}/src.db";
        \touch($tmp);

        $this->expectException(EngineException::class);
        $this->engine->restore(new RestoreOptions(
            engine: 'sqlite',
            sourcePath: $tmp,
            database: '',
        ));
    }

    public function testRestoreThrowsWhenSourceNotReadable(): void
    {
        $this->expectException(EngineException::class);
        $this->engine->restore(new RestoreOptions(
            engine: 'sqlite',
            sourcePath: '/nonexistent.db',
            database: "{$this->tempDir}/dest.db",
        ));
    }

    public function testRestoreCreatesNestedDirectories(): void
    {
        $dbFile = "{$this->tempDir}/source.db";
        $db     = new \SQLite3($dbFile);
        $db->exec('CREATE TABLE x (id INTEGER PRIMARY KEY)');
        $db->close();

        $artifact    = $this->engine->dump(new DumpOptions(engine: 'sqlite', database: $dbFile));
        $restorePath = "{$this->tempDir}/nested/dir/restored.db";

        $this->engine->restore(new RestoreOptions(
            engine: 'sqlite',
            sourcePath: $artifact->localPath,
            database: $restorePath,
        ));

        $this->assertFileExists($restorePath);
        \unlink($artifact->localPath);
    }

    public function testRestoreInMemoryViaSQLite3(): void
    {
        // Create a source db file to restore from
        $dbFile = "{$this->tempDir}/source.db";
        $src    = new \SQLite3($dbFile);
        $src->exec('CREATE TABLE foo (bar TEXT)');
        $src->exec("INSERT INTO foo VALUES ('baz')");
        $src->close();

        $dest = new \SQLite3(':memory:');

        $this->engine->restore(new RestoreOptions(
            engine: 'sqlite',
            sourcePath: $dbFile,
            database: ':memory:',
            customOptions: ['connection' => $dest],
        ));

        $val = $dest->querySingle('SELECT bar FROM foo');
        $this->assertSame('baz', $val);
        $dest->close();
    }

    public function testRestoreInMemoryViaPDO(): void
    {
        $dbFile = "{$this->tempDir}/pdo_src.db";
        $srcDb  = new \SQLite3($dbFile);
        $srcDb->exec('CREATE TABLE things (id INTEGER PRIMARY KEY, label TEXT)');
        $srcDb->exec("INSERT INTO things VALUES (1, 'hello')");
        $srcDb->close();

        $dest = new \PDO('sqlite::memory:');
        $dest->exec('CREATE TABLE things (id INTEGER PRIMARY KEY, label TEXT)');

        $this->engine->restore(new RestoreOptions(
            engine: 'sqlite',
            sourcePath: $dbFile,
            database: 'file::memory:?cache=shared',
            customOptions: ['connection' => $dest],
        ));

        // After ATTACH + INSERT, the data should be present
        $stmt = $dest->query('SELECT label FROM main.things WHERE id = 1');
        $this->assertNotFalse($stmt);
    }

    public function testDumpInMemoryFallback(): void
    {
        $artifact = $this->engine->dump(new DumpOptions(
            engine: 'sqlite',
            database: ':memory:',
        ));

        $this->assertFileExists($artifact->localPath);
        $this->assertGreaterThan(0, \filesize($artifact->localPath));
        \unlink($artifact->localPath);
    }

    public function testRestoreInMemoryFallback(): void
    {
        $dbFile = "{$this->tempDir}/source.db";
        $src    = new \SQLite3($dbFile);
        $src->exec('CREATE TABLE foo (bar TEXT)');
        $src->close();

        $destFile = "{$this->tempDir}/dest_memory.db";

        $this->engine->restore(new RestoreOptions(
            engine: 'sqlite',
            sourcePath: $dbFile,
            database: $destFile,
        ));

        $this->assertFileExists($destFile);
    }

    public function testDumpInMemoryThrowsOnFailure(): void
    {
        $pdo = $this->createMock(\PDO::class);
        $pdo->method('exec')->willThrowException(new \Exception('PDO vacuum failed'));

        $this->expectException(EngineException::class);
        $this->expectExceptionMessageIsOrContains('Failed to dump SQLite in-memory database');

        $this->engine->dump(new DumpOptions(
            engine: 'sqlite',
            database: ':memory:',
            customOptions: ['connection' => $pdo],
        ));
    }

    public function testRestoreInMemoryThrowsOnFailure(): void
    {
        $dbFile = "{$this->tempDir}/source.db";
        \touch($dbFile);

        $pdo = $this->createMock(\PDO::class);
        $pdo->method('exec')->willThrowException(new \Exception('PDO attach failed'));

        $this->expectException(EngineException::class);
        $this->expectExceptionMessageIsOrContains('Failed to restore SQLite in-memory database');

        $this->engine->restore(new RestoreOptions(
            engine: 'sqlite',
            sourcePath: $dbFile,
            database: ':memory:',
            customOptions: ['connection' => $pdo],
        ));
    }

    public function testRestoreThrowsWhenDirCreationFails(): void
    {
        $dbFile = "{$this->tempDir}/source.db";
        \touch($dbFile);

        // Block directory creation by creating a file
        $blockingFile = "{$this->tempDir}/blocker";
        \file_put_contents($blockingFile, 'content');

        $this->expectException(EngineException::class);
        $this->expectExceptionMessageIsOrContains('Failed to create directory');

        $this->engine->restore(new RestoreOptions(
            engine: 'sqlite',
            sourcePath: $dbFile,
            database: "{$blockingFile}/nested/db.sqlite",
        ));
    }


    // -------------------------------------------------------------------------
    // Helper
    // -------------------------------------------------------------------------

    private function removeDir(string $path): void
    {
        if (!\is_dir($path)) {
            return;
        }
        foreach (\array_diff(\scandir($path) ?: [], ['.', '..']) as $entry) {
            $full = "{$path}/{$entry}";
            \is_dir($full) ? $this->removeDir($full) : \unlink($full);
        }
        \rmdir($path);
    }
}
