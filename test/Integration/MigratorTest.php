<?php

declare(strict_types=1);

namespace OpenCCK\Test\Integration;

use Amp\PHPUnit\AsyncTestCase;
use Amp\Postgres\PostgresConnection;
use Amp\Sql\SqlQueryError;
use OpenCCK\Infrastructure\Config\Config;
use OpenCCK\Infrastructure\Database\Database;
use OpenCCK\Infrastructure\Database\MigrationSet;
use OpenCCK\Infrastructure\Database\Migrator;
use Psr\Log\NullLogger;

/**
 * Needs a real PostgreSQL and runs on the project server: docker compose --profile test run --rm test.
 * Works only in a database whose name ends with "_test" and never touches the application database.
 *
 * @group integration
 */
final class MigratorTest extends AsyncTestCase {
    private PostgresConnection $db;

    protected function setUp(): void {
        parent::setUp();
        $settings = Config::fromEnv(getenv())->postgres;
        if (!str_ends_with($settings->database, '_test')) {
            self::fail(sprintf('Integration tests need a database ending with _test, got %s', $settings->database));
        }

        $admin = Database::connect($settings, 'postgres');
        $exists = $admin->execute('SELECT 1 FROM pg_database WHERE datname = ?', [$settings->database])->fetchRow();
        if ($exists === null) {
            $admin->query(sprintf('CREATE DATABASE "%s"', $settings->database));
        }
        $admin->close();

        $this->db = Database::connect($settings);
        $this->db->query('DROP SCHEMA IF EXISTS public CASCADE');
        $this->db->query('CREATE SCHEMA public');
    }

    protected function tearDown(): void {
        $this->db->close();
        parent::tearDown();
    }

    public function testAppliesOnceAndIsIdempotent(): void {
        $set = MigrationSet::fromFiles([
            '0001_a.sql' => 'CREATE TABLE a (id int)',
            '0002_b.sql' => 'CREATE TABLE b (id int)',
        ]);
        $migrator = new Migrator($this->db, new NullLogger());

        self::assertSame([1, 2], $migrator->migrate($set));
        self::assertSame([], $migrator->migrate($set));
    }

    public function testFailedMigrationLeavesNothingApplied(): void {
        $set = MigrationSet::fromFiles([
            '0001_a.sql' => 'CREATE TABLE a (id int)',
            '0002_bad.sql' => 'THIS IS NOT SQL',
        ]);

        try {
            (new Migrator($this->db, new NullLogger()))->migrate($set);
            self::fail('Expected the broken migration to throw');
        } catch (SqlQueryError) {
        }

        $row = $this->db->query("SELECT to_regclass('public.a') IS NULL AS missing, to_regclass('public.schema_migrations') IS NULL AS no_log")->fetchRow();
        self::assertSame(['missing' => true, 'no_log' => true], $row);
    }

    public function testProjectMigrationsApply(): void {
        $applied = (new Migrator($this->db, new NullLogger()))->migrate(Migrator::loadDirectories(...\OpenCCK\Infrastructure\Database\MigrationFolders::of(dirname(__DIR__, 2))));

        self::assertNotEmpty($applied);
    }
}
