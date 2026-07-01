<?php

declare(strict_types=1);

namespace MonkeysLegion\Backup\Tests\Unit;

use MonkeysLegion\Backup\Contract\EngineInterface;
use MonkeysLegion\Backup\Engine\EngineName;
use MonkeysLegion\Backup\Engine\EngineRegistry;
use MonkeysLegion\Backup\Engine\MysqlEngine;
use MonkeysLegion\Backup\Engine\PostgresEngine;
use MonkeysLegion\Backup\Engine\MongodbEngine;
use MonkeysLegion\Backup\Engine\RedisEngine;
use MonkeysLegion\Backup\Engine\SqliteEngine;
use MonkeysLegion\Backup\Exception\EngineException;
use PHPUnit\Framework\TestCase;

final class EngineRegistryTest extends TestCase
{
    // -------------------------------------------------------------------------
    // default()
    // -------------------------------------------------------------------------

    public function testDefaultRegistersAllFiveBuiltInEngines(): void
    {
        $registry = EngineRegistry::default();

        $this->assertInstanceOf(MysqlEngine::class,    $registry->get('mysql'));
        $this->assertInstanceOf(PostgresEngine::class, $registry->get('postgres'));
        $this->assertInstanceOf(MongodbEngine::class,  $registry->get('mongodb'));
        $this->assertInstanceOf(RedisEngine::class,    $registry->get('redis'));
        $this->assertInstanceOf(SqliteEngine::class,   $registry->get('sqlite'));
    }

    public function testDefaultKeysMatchEngineName(): void
    {
        $all = EngineRegistry::default()->all();

        $this->assertCount(5, $all);
        foreach (EngineName::cases() as $case) {
            $this->assertArrayHasKey($case->value, $all);
        }
    }

    // -------------------------------------------------------------------------
    // get() / has()
    // -------------------------------------------------------------------------

    public function testGetThrowsForUnregisteredName(): void
    {
        $this->expectException(EngineException::class);
        $this->expectExceptionMessageMatches('/No engine registered for "mysql"/');

        (new EngineRegistry())->get('mysql');
    }

    public function testHasReturnsFalseForUnregisteredEngine(): void
    {
        $this->assertFalse((new EngineRegistry())->has('mysql'));
    }

    public function testHasReturnsTrueAfterRegistration(): void
    {
        $registry = new EngineRegistry();
        $registry->register('mysql', new MysqlEngine());

        $this->assertTrue($registry->has('mysql'));
    }

    // -------------------------------------------------------------------------
    // register() — custom string key
    // -------------------------------------------------------------------------

    public function testRegisterAcceptsArbitraryStringKey(): void
    {
        $registry = new EngineRegistry();
        $stub = $this->createStub(EngineInterface::class);

        $registry->register('cassandra', $stub);

        $this->assertTrue($registry->has('cassandra'));
        $this->assertSame($stub, $registry->get('cassandra'));
    }

    public function testRegisterOverridesExistingEngine(): void
    {
        $registry = EngineRegistry::default();
        $stub = $this->createStub(EngineInterface::class);

        $registry->register('mysql', $stub);

        $this->assertSame($stub, $registry->get('mysql'));
    }

    // -------------------------------------------------------------------------
    // all()
    // -------------------------------------------------------------------------

    public function testAllReturnsCustomEnginesAlongsideBuiltIns(): void
    {
        $registry = EngineRegistry::default();
        $stub = $this->createStub(EngineInterface::class);
        $registry->register('dynamodb', $stub);

        $all = $registry->all();
        $this->assertCount(6, $all);
        $this->assertArrayHasKey('dynamodb', $all);
        $this->assertSame($stub, $all['dynamodb']);
    }

    // -------------------------------------------------------------------------
    // get() error message lists registered engines
    // -------------------------------------------------------------------------

    public function testGetErrorMessageListsRegisteredEngines(): void
    {
        $registry = new EngineRegistry();
        $registry->register('mysql', new MysqlEngine());

        try {
            $registry->get('cassandra');
            $this->fail('Expected EngineException');
        } catch (EngineException $e) {
            $this->assertStringContainsString('mysql', $e->getMessage());
        }
    }
}
