<?php

declare(strict_types=1);

namespace OpenCCK\Test\Integration;

use Amp\PHPUnit\AsyncTestCase;
use Amp\Postgres\PostgresConnectionPool;
use OpenCCK\Infrastructure\Config\Config;
use OpenCCK\Infrastructure\Database\Database;
use OpenCCK\Infrastructure\Database\MigrationSet;
use OpenCCK\Infrastructure\Database\Migrator;
use Psr\Log\NullLogger;

/**
 * Needs a real PostgreSQL (POSTGRES_* in the environment); runs on the project server, not in CI.
 *
 * @group integration
 */
final class MigratorTest extends AsyncTestCase {
    private PostgresConnectionPool $db;
    private string $schema;

    protected function setUp(): void {
        parent::setUp();
        $this->db = Database::pool(Config::fromEnv(getenv())->postgres, 2);
        $this->schema = 'test_' . bin2hex(random_bytes(4));
        $this->db->query("CREATE SCHEMA {$this->schema}");
        $this->db->query("SET search_path TO {$this->schema}");
    }

    protected function tearDown(): void {
        $this->db->query("DROP SCHEMA IF EXISTS {$this->schema} CASCADE");
        $this->db->close();
        parent::tearDown();
    }

    public function testAppliesOnceAndIsIdempotent(): void {
        $set = MigrationSet::fromFiles([
            '0001_a.sql' => "CREATE TABLE {$this->schema}.a (id int)",
            '0002_b.sql' => "CREATE TABLE {$this->schema}.b (id int)",
        ]);
        $migrator = new Migrator($this->db, new NullLogger());

        self::assertSame([1, 2], $migrator->migrate($set));
        self::assertSame([], $migrator->migrate($set));
    }

    public function testFailedMigrationLeavesNothingApplied(): void {
        $set = MigrationSet::fromFiles([
            '0001_a.sql' => "CREATE TABLE {$this->schema}.a (id int)",
            '0002_bad.sql' => 'THIS IS NOT SQL',
        ]);

        try {
            (new Migrator($this->db, new NullLogger()))->migrate($set);
            self::fail('Expected the broken migration to throw');
        } catch (\Throwable) {
        }

        $exists = $this->db->execute('SELECT to_regclass(?) AS t', ["{$this->schema}.a"])->fetchRow();
        self::assertNull($exists['t'] ?? null);
    }
}
