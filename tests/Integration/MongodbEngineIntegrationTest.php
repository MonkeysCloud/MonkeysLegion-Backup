<?php

declare(strict_types=1);

namespace MonkeysLegion\Backup\Tests\Integration;

use MonkeysLegion\Backup\Engine\MongodbEngine;
use MonkeysLegion\Backup\ValueObject\DumpOptions;
use MonkeysLegion\Backup\ValueObject\RestoreOptions;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Integration test for MongodbEngine against a live MongoDB instance.
 *
 * Prerequisites (managed via docker-compose.testing.yml):
 *   docker compose -f docker-compose.testing.yml up -d --wait
 *
 * Run only this group:
 *   vendor/bin/phpunit --group mongodb
 */
#[Group('mongodb')]
#[Group('db')]
final class MongodbEngineIntegrationTest extends TestCase
{
    use IntegrationEnv;

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

        $this->skipUnlessDockerServiceReachable('MongoDB', $this->host, $this->port);

        $this->engine = new MongodbEngine();

        \exec("mongosh --host {$this->host} --port {$this->port} {$this->database} --eval \"db.users.drop(); db.users.insertOne({name: 'Alice'});\"");
    }

    public function testDumpAndRestoreRoundTrip(): void
    {
        $artifact = $this->engine->dump(new DumpOptions(
            engine: 'mongodb',
            host: $this->host,
            port: $this->port,
            database: $this->database
        ));

        $this->assertFileExists($artifact->localPath);
        $this->assertGreaterThan(0, \filesize($artifact->localPath));

        \exec("mongosh --host {$this->host} --port {$this->port} {$this->database} --eval \"db.dropDatabase();\"");

        \exec("mongosh --host {$this->host} --port {$this->port} {$this->database} --quiet --eval \"db.users.countDocuments()\"", $outputCount);
        $countBefore = (int)\trim(\implode('', $outputCount));
        $this->assertSame(0, $countBefore);

        $this->engine->restore(new RestoreOptions(
            engine: 'mongodb',
            sourcePath: $artifact->localPath,
            host: $this->host,
            port: $this->port,
            database: $this->database
        ));

        unset($outputCount);
        \exec("mongosh --host {$this->host} --port {$this->port} {$this->database} --quiet --eval \"db.users.findOne({name: 'Alice'}).name\"", $outputName);
        $restoredName = \trim(\implode('', $outputName));

        $this->assertSame('Alice', $restoredName);

        \unlink($artifact->localPath);
    }
}
