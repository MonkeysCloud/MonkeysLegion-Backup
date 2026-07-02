<?php

declare(strict_types=1);

namespace MonkeysLegion\Backup\Tests\Integration;

use MonkeysLegion\Backup\Engine\RedisEngine;
use MonkeysLegion\Backup\ValueObject\DumpOptions;
use MonkeysLegion\Backup\ValueObject\RestoreOptions;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Integration test for RedisEngine against a live Redis instance.
 *
 * Prerequisites (managed via docker-compose.testing.yml):
 *   docker compose -f docker-compose.testing.yml up -d --wait
 *
 * Run only this group:
 *   vendor/bin/phpunit --group redis
 */
#[Group('redis')]
#[Group('db')]
final class RedisEngineIntegrationTest extends TestCase
{
    use IntegrationEnv;

    private string $host;
    private int    $port;
    private string $authUser;
    private string $authPassword;
    private RedisEngine $engine;
    private string $tempDir;

    protected function setUp(): void
    {
        $env = $this->loadEnv();
        $this->host = $env['REDIS_HOST'] ?? '127.0.0.1';
        $this->port = (int)($env['REDIS_PORT'] ?? 6380);
        $this->authUser = $env['REDIS_USER'] ?? 'backupuser';
        $this->authPassword = $env['REDIS_PASSWORD'] ?? 'analikayn';

        $this->skipUnlessDockerServiceReachable('Redis', $this->host, $this->port);

        $this->engine = new RedisEngine();
        $this->tempDir = \sys_get_temp_dir() . '/mb_redis_test';
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

    public function testPasswordlessDumpAndCopyRestore(): void
    {
        \exec("redis-cli -h {$this->host} -p {$this->port} SET mb_test_key hello");

        $artifact = $this->engine->dump(new DumpOptions(
            engine: 'redis',
            host: $this->host,
            port: $this->port,
            database: 'mb_redis'
        ));

        $this->assertFileExists($artifact->localPath);
        $this->assertGreaterThan(0, \filesize($artifact->localPath));

        $targetRdb = "{$this->tempDir}/restored_dump.rdb";
        $this->engine->restore(new RestoreOptions(
            engine: 'redis',
            sourcePath: $artifact->localPath,
            database: $targetRdb
        ));

        $this->assertFileExists($targetRdb);
        $this->assertSame(\filesize($artifact->localPath), \filesize($targetRdb));

        \unlink($artifact->localPath);
    }

    public function testPasswordAuthenticatedDump(): void
    {
        if ($this->authUser === '' || $this->authPassword === '') {
            $this->markTestSkipped('REDIS_USER and REDIS_PASSWORD must be set for authenticated dump test.');
        }

        $artifact = $this->engine->dump(new DumpOptions(
            engine: 'redis',
            host: $this->host,
            port: $this->port,
            user: $this->authUser,
            password: $this->authPassword,
            database: 'mb_redis_auth'
        ));

        $this->assertFileExists($artifact->localPath);
        $this->assertGreaterThan(0, \filesize($artifact->localPath));

        \unlink($artifact->localPath);
    }
}
