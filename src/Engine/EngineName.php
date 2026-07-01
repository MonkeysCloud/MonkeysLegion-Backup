<?php

declare(strict_types=1);

namespace MonkeysLegion\Backup\Engine;

/**
 * Backed string enum of all supported database engine names.
 * Using the enum value as the CLI argument for engine selection.
 */
enum EngineName: string
{
    case Mysql    = 'mysql';
    case Postgres = 'postgres';
    case MongoDB  = 'mongodb';
    case Redis    = 'redis';
    case SQLite   = 'sqlite';
}
