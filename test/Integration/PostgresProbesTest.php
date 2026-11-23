<?php

declare(strict_types=1);

namespace OpenCCK\Test\Integration;

use Amp\PHPUnit\AsyncTestCase;
use Amp\Postgres\PostgresConnection;
use LengthException;
use Mercurius\Memory\Domain\Probes;
use Mercurius\Memory\Infrastructure\PostgresProbes;
use OpenCCK\Test\Support\TestDatabase;

/**
 * The owner's probes in the database: added in order, marked, removed, never more than the limit.
 *
 * spec: brief/memory.md#пробы-владельца
 *
 * @group integration
 */
final class PostgresProbesTest extends AsyncTestCase {
    private PostgresConnection $db;

    protected function setUp(): void {
        parent::setUp();
        $this->db = TestDatabase::connect();
        $this->db->query('DELETE FROM memory_probes');
    }

    public function testProbesAreKeptMarkedAndRemoved(): void {
        $probes = new PostgresProbes($this->db);
        $first = $probes->add('какой кофе пьёт владелец', 7);
        $probes->add('где стоит pve-01', 9);
        $probes->mark($first->id, true);

        $all = $probes->all();
        self::assertSame(['какой кофе пьёт владелец', 'где стоит pve-01'], array_map(static fn($p) => $p->question, $all));
        self::assertSame([true, false], array_map(static fn($p) => $p->missing, $all));
        self::assertSame(7, $all[0]->factId);

        self::assertTrue($probes->remove($first->id));
        self::assertFalse($probes->remove($first->id));
        self::assertCount(1, $probes->all());
    }

    public function testTheListStopsAtItsLimit(): void {
        $probes = new PostgresProbes($this->db);
        for ($i = 0; $i < Probes::MAX; $i++) {
            $probes->add('вопрос ' . $i, 1);
        }

        $this->expectException(LengthException::class);
        $probes->add('лишний', 1);
    }

    protected function tearDown(): void {
        $this->db->query('DELETE FROM memory_probes');
        $this->db->close();
        parent::tearDown();
    }
}
