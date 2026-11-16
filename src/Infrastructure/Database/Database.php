<?php

declare(strict_types=1);

namespace OpenCCK\Infrastructure\Database;

use Amp\Postgres\PostgresConfig;
use Amp\Postgres\PostgresConnection;
use Amp\Postgres\PostgresConnectionPool;
use OpenCCK\Infrastructure\Config\PostgresSettings;

use function Amp\Postgres\connect;

/**
 * Factory for the PostgreSQL connection pool (amphp/postgres).
 */
final class Database {
    public static function pool(PostgresSettings $settings, int $maxConnections = 10): PostgresConnectionPool {
        return new PostgresConnectionPool(self::config($settings), max(1, $maxConnections));
    }

    /** A single connection: session settings (search_path, ...) stay in effect, unlike in the pool. */
    public static function connect(PostgresSettings $settings, ?string $database = null): PostgresConnection {
        return connect(self::config($settings, $database));
    }

    private static function config(PostgresSettings $settings, ?string $database = null): PostgresConfig {
        return new PostgresConfig(
            host: $settings->host,
            port: $settings->port,
            user: $settings->user,
            password: $settings->password(),
            database: $database ?? $settings->database,
            applicationName: 'mercurius',
        );
    }
}
