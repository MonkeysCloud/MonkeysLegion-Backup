<?php

declare(strict_types=1);

namespace MonkeysLegion\Backup\Tests\Integration;

use MonkeysLegion\Backup\Engine\RedisEngine;
use MonkeysLegion\Backup\ValueObject\DumpOptions;
use MonkeysLegion\Backup\ValueObject\RestoreOptions;
use PHPUnit\Framework\TestCase;

final class RedisEngineIntegrationTest extends TestCase
{
    private string $host;
    private int    $port;
    private RedisEngine $engine;
    private string $tempDir;

    protected function setUp(): void
    {
        $env = $this->loadEnv();
        $this->host = $env['REDIS_HOST'] ?? '127.0.0.1';
        $this->port = (int)($env['REDIS_PORT'] ?? 6379);

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
        // 1. Set a value in redis using redis-cli (no auth needed by default)
        \exec("redis-cli -h {$this->host} -p {$this->port} SET mb_test_key hello");

        // 2. Dump
        $artifact = $this->engine->dump(new DumpOptions(
            engine: 'redis',
            host: $this->host,
            port: $this->port,
            database: 'mb_redis'
        ));

        $this->assertFileExists($artifact->localPath);
        $this->assertGreaterThan(0, \filesize($artifact->localPath));

        // 3. Restore to a dummy target path (since replacing server active RDB requires root/restart)
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
        // 1. Dump using the backupuser ACL user and password 'analikayn'
        $artifact = $this->engine->dump(new DumpOptions(
            engine: 'redis',
            host: $this->host,
            port: $this->port,
            user: 'backupuser',
            password: 'analikayn',
            database: 'mb_redis_auth'
        ));

        $this->assertFileExists($artifact->localPath);
        $this->assertGreaterThan(0, \filesize($artifact->localPath));

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
