<?php

declare(strict_types=1);

namespace OpenCCK\Test\Support;

use Amp\Postgres\PostgresConnection;
use OpenCCK\Infrastructure\Config\Config;
use OpenCCK\Infrastructure\Database\Database;
use OpenCCK\Infrastructure\Database\Migrator;
use PHPUnit\Framework\Assert;
use Psr\Log\NullLogger;

/**
 * A clean test database for integration tests: only a database whose name ends with "_test",
 * an empty public schema, optionally with all project migrations applied.
 */
final class TestDatabase {
    public static function connect(bool $migrate = true): PostgresConnection {
        $settings = Config::fromEnv(getenv())->postgres;
        if (!str_ends_with($settings->database, '_test')) {
            Assert::fail(sprintf('Integration tests need a database ending with _test, got %s', $settings->database));
        }

        $admin = Database::connect($settings, 'postgres');
        $exists = $admin->execute('SELECT 1 FROM pg_database WHERE datname = ?', [$settings->database])->fetchRow();
        if ($exists === null) {
            $admin->query(sprintf('CREATE DATABASE "%s"', $settings->database));
        }
        $admin->close();

        $db = Database::connect($settings);
        $db->query('DROP SCHEMA IF EXISTS public CASCADE');
        $db->query('CREATE SCHEMA public');
        if ($migrate) {
            (new Migrator($db, new NullLogger()))->migrate(Migrator::loadDirectories(...\OpenCCK\Infrastructure\Database\MigrationFolders::of(dirname(__DIR__, 2))));
        }
        return $db;
    }

    /**
     * Creates a participant and a conversation.
     *
     * @return array{int, int} [principalId, conversationId]
     */
    public static function conversation(PostgresConnection $db, string $chatId = '1'): array {
        $principal = $db->execute("INSERT INTO principals (kind, role) VALUES ('human', 'user') RETURNING id")->fetchRow();
        $principalId = $principal['id'] ?? null;
        Assert::assertIsInt($principalId);
        $conversation = $db->execute(
            "INSERT INTO conversations (principal_id, channel, chat_id) VALUES (?, 'test', ?) RETURNING id",
            [$principalId, $chatId],
        )->fetchRow();
        $conversationId = $conversation['id'] ?? null;
        Assert::assertIsInt($conversationId);
        return [$principalId, $conversationId];
    }
}
