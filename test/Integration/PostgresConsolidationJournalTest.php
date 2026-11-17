<?php

declare(strict_types=1);

namespace OpenCCK\Test\Integration;

use Amp\PHPUnit\AsyncTestCase;
use Amp\Postgres\PostgresConnection;
use OpenCCK\Memory\Domain\ConsolidationJournal;
use OpenCCK\Memory\Infrastructure\PostgresConsolidationJournal;
use OpenCCK\Test\Support\TestDatabase;

/**
 * @group integration
 */
final class PostgresConsolidationJournalTest extends AsyncTestCase {
    private PostgresConnection $db;
    private PostgresConsolidationJournal $journal;

    protected function setUp(): void {
        parent::setUp();
        $this->db = TestDatabase::connect();
        $this->journal = new PostgresConsolidationJournal($this->db);
    }

    protected function tearDown(): void {
        $this->db->close();
        parent::tearDown();
    }

    public function testPassesAndPauses(): void {
        self::assertNull($this->journal->last());
        $this->journal->save(ConsolidationJournal::DONE, 4, 120, 15, ['одно замечание']);
        $this->journal->save(ConsolidationJournal::FAILED, 0, 0, 1, []);

        $last = $this->journal->last();
        self::assertNotNull($last);
        self::assertSame([4, 120, ConsolidationJournal::DONE], [$last['facts'], $last['chars'], $last['status']]);

        self::assertTrue($this->journal->notice('duplicate', 1, 2, 180));
        self::assertFalse($this->journal->notice('duplicate', 1, 2, 180));
        self::assertTrue($this->journal->notice('duplicate', 1, 3, 180));
        $this->db->query("UPDATE memory_notices SET shown_at = now() - interval '200 days' WHERE a = 1 AND b = 2");
        self::assertTrue($this->journal->notice('duplicate', 1, 2, 180), 'shown again after the pause');
    }
}
