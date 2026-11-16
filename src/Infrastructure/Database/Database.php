<?php

declare(strict_types=1);

namespace OpenCCK\Infrastructure\Database;

use Amp\Postgres\PostgresConfig;
use Amp\Postgres\PostgresConnectionPool;
use OpenCCK\Infrastructure\Config\PostgresSettings;

/**
 * Factory for the PostgreSQL connection pool (amphp/postgres).
 */
final class Database {
    public static function pool(PostgresSettings $settings, int $maxConnections = 10): PostgresConnectionPool {
        $config = new PostgresConfig(
            host: $settings->host,
            port: $settings->port,
            user: $settings->user,
            password: $settings->password(),
            database: $settings->database,
            applicationName: 'mercurius',
        );

        return new PostgresConnectionPool($config, max(1, $maxConnections));
    }
}
