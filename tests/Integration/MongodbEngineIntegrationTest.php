<?php

declare(strict_types=1);

namespace MonkeysLegion\Backup\Tests\Integration;

use MonkeysLegion\Backup\Engine\MongodbEngine;
use MonkeysLegion\Backup\ValueObject\DumpOptions;
use MonkeysLegion\Backup\ValueObject\RestoreOptions;
use PHPUnit\Framework\TestCase;

final class MongodbEngineIntegrationTest extends TestCase
{
    private string $host;
    private int    $port;
    private string $database;
    private MongodbEngine $engine;

    protected function setUp(): void
    {
        $env = $this->loadEnv();
        $this->host = $env['MONGO_HOST'] ?? '127.0.0.1';
        $this->port = (int)($env['MONGO_PORT'] ?? 27017);
        $this->database = $env['MONGO_TEST_DB'] ?? 'mb_test_mongo';

        $this->engine = new MongodbEngine();

        // Seed some test data using mongosh CLI
        \exec("mongosh --host {$this->host} --port {$this->port} {$this->database} --eval \"db.users.drop(); db.users.insertOne({name: 'Alice'});\"");
    }

    public function testDumpAndRestoreRoundTrip(): void
    {
        // 1. Dump
        $artifact = $this->engine->dump(new DumpOptions(
            engine: 'mongodb',
            host: $this->host,
            port: $this->port,
            database: $this->database
        ));

        $this->assertFileExists($artifact->localPath);
        $this->assertGreaterThan(0, \filesize($artifact->localPath));

        // 2. Drop the db so we know the restore actually brought the data back
        \exec("mongosh --host {$this->host} --port {$this->port} {$this->database} --eval \"db.dropDatabase();\"");

        // Verify it was dropped
        \exec("mongosh --host {$this->host} --port {$this->port} {$this->database} --quiet --eval \"db.users.countDocuments()\"", $outputCount);
        $countBefore = (int)\trim(\implode('', $outputCount));
        $this->assertSame(0, $countBefore);

        // 3. Restore
        $this->engine->restore(new RestoreOptions(
            engine: 'mongodb',
            sourcePath: $artifact->localPath,
            host: $this->host,
            port: $this->port,
            database: $this->database
        ));

        // 4. Verify data is back
        unset($outputCount);
        \exec("mongosh --host {$this->host} --port {$this->port} {$this->database} --quiet --eval \"db.users.findOne({name: 'Alice'}).name\"", $outputName);
        $restoredName = \trim(\implode('', $outputName));

        $this->assertSame('Alice', $restoredName);

        \unlink($artifact->localPath);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
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
}
