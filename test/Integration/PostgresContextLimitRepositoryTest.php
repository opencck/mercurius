<?php

declare(strict_types=1);

namespace OpenCCK\Test\Integration;

use Amp\PHPUnit\AsyncTestCase;
use Amp\Postgres\PostgresConnection;
use OpenCCK\Domain\Repository\ContextLimitRepository;
use OpenCCK\Infrastructure\Repository\PostgresContextLimitRepository;
use OpenCCK\Test\Support\TestDatabase;

/**
 * The limits of the context in the database: each value kept on its own, both null — the row goes.
 *
 * spec: brief/agent-loop.md#объём-беседы-и-порог-сжатия
 *
 * @group integration
 */
final class PostgresContextLimitRepositoryTest extends AsyncTestCase {
    private PostgresConnection $db;

    protected function setUp(): void {
        parent::setUp();
        $this->db = TestDatabase::connect();
    }

    public function testLimitsAreKeptAndClearedByKindAndId(): void {
        $limits = new PostgresContextLimitRepository($this->db);
        $id = random_int(1_000_000, 9_000_000);
        self::assertSame([null, null], $limits->get(ContextLimitRepository::PROJECT, $id));

        $limits->save(ContextLimitRepository::PROJECT, $id, 60_000, null);
        $limits->save(ContextLimitRepository::PROJECT, $id, 60_000, 70);
        $limits->save(ContextLimitRepository::PRINCIPAL, $id, null, 50);
        self::assertSame([60_000, 70], $limits->get(ContextLimitRepository::PROJECT, $id));
        self::assertSame([null, 50], $limits->get(ContextLimitRepository::PRINCIPAL, $id));

        $limits->save(ContextLimitRepository::PROJECT, $id, null, null);
        self::assertSame([null, null], $limits->get(ContextLimitRepository::PROJECT, $id));
        $row = $this->db->execute('SELECT count(*) AS n FROM context_limits WHERE kind = ? AND ref_id = ?', [ContextLimitRepository::PROJECT, $id])->fetchRow();
        self::assertSame(0, $row['n'] ?? null);
        $limits->save(ContextLimitRepository::PRINCIPAL, $id, null, null);
    }

    protected function tearDown(): void {
        $this->db->close();
        parent::tearDown();
    }
}
