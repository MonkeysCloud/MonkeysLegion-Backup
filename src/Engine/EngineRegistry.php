<?php

declare(strict_types=1);

namespace MonkeysLegion\Backup\Engine;

use MonkeysLegion\Backup\Contract\EngineInterface;
use MonkeysLegion\Backup\Exception\EngineException;

/**
 * Central registry that maps engine name strings to {@see EngineInterface} instances.
 *
 * All five built-in engines are pre-registered via {@see self::default()}.
 * Third-party engines can be registered under any non-empty string key:
 *
 *   $registry = EngineRegistry::default();
 *   $registry->register('cassandra', new CassandraEngine());
 *   $engine = $registry->get('cassandra');
 *
 * The {@see EngineName} enum is still available as a typed constant bag for
 * built-in string values (e.g. EngineName::Mysql->value === 'mysql'), but it
 * is NOT part of the registry API — everything is keyed by plain string.
 */
final class EngineRegistry
{
    /** @var array<string, EngineInterface> */
    private array $engines = [];

    // -------------------------------------------------------------------------
    // Static factory
    // -------------------------------------------------------------------------

    /**
     * Build a registry pre-populated with all five built-in engines.
     */
    public static function default(): self
    {
        $registry = new self();

        $registry->register(EngineName::Mysql->value,    new MysqlEngine());
        $registry->register(EngineName::Postgres->value, new PostgresEngine());
        $registry->register(EngineName::MongoDB->value,  new MongodbEngine());
        $registry->register(EngineName::Redis->value,    new RedisEngine());
        $registry->register(EngineName::SQLite->value,   new SqliteEngine());

        return $registry;
    }

    // -------------------------------------------------------------------------
    // Mutation
    // -------------------------------------------------------------------------

    /**
     * Register (or replace) an engine under the given string name.
     *
     *   $registry->register('mysql',     new TunedMysqlEngine($runner));
     *   $registry->register('cassandra', new CassandraEngine());
     */
    public function register(string $name, EngineInterface $engine): void
    {
        $this->engines[$name] = $engine;
    }

    // -------------------------------------------------------------------------
    // Query
    // -------------------------------------------------------------------------

    /**
     * Retrieve the engine registered under the given string name.
     *
     * @throws EngineException if no engine is registered under that name.
     */
    public function get(string $name): EngineInterface
    {
        if (!isset($this->engines[$name])) {
            $known = \implode(', ', \array_keys($this->engines));
            throw new EngineException("No engine registered for \"{$name}\". Registered: {$known}.");
        }

        return $this->engines[$name];
    }

    /**
     * Check whether an engine is registered under the given string name.
     */
    public function has(string $name): bool
    {
        return isset($this->engines[$name]);
    }

    /**
     * Return all registered engines indexed by their string name.
     *
     * @return array<string, EngineInterface>
     */
    public function all(): array
    {
        return $this->engines;
    }
}
