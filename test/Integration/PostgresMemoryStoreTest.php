<?php

declare(strict_types=1);

namespace OpenCCK\Test\Integration;

use Amp\Postgres\PostgresConnection;
use Mercurius\Memory\Infrastructure\PostgresMemoryStore;
use Mercurius\Memory\Testing\MemoryStoreContract;
use OpenCCK\Test\Support\TestDatabase;

/**
 * @group integration
 */
final class PostgresMemoryStoreTest extends MemoryStoreContract {
    private PostgresConnection $db;

    protected function setUp(): void {
        parent::setUp();
        $this->db = TestDatabase::connect();
        [, $this->conversationId] = TestDatabase::conversation($this->db);
        $this->store = new PostgresMemoryStore($this->db);
    }

    public function testARunTakesTheRecallsMadeForItWithTheVersions(): void {
        // spec: brief/operations.md#квитанция-извлечения-памяти — a receipt of recall: which run got which version of which fact
        $fact = $this->store->addFact('owner', \Mercurius\Memory\Domain\Fact::KNOWLEDGE, 'квитанция', ['квитанция'], 0.5, \Mercurius\Memory\Domain\Fact::AGENT, null);
        $this->store->markRecalled($this->conversationId, [$fact->id], [$fact->id => $fact->version]);
        $row = $this->db->execute('SELECT principal_id FROM conversations WHERE id = ?', [$this->conversationId])->fetchRow() ?? [];
        $principal = isset($row['principal_id']) && is_int($row['principal_id']) ? $row['principal_id'] : 0;

        $run = (new \OpenCCK\Infrastructure\Repository\PostgresRunRepository($this->db))->start($this->conversationId, $principal, 'openrouter:x/y', new \OpenCCK\Domain\Agent\RunLimits(30, 1000, 900, 120));

        self::assertNotNull($run);
        self::assertSame([['fact' => $fact->id, 'version' => $fact->version, 'helpful' => null]], $this->store->receipt($run->id));

        // spec: brief/memory.md#факты — whether the answer was helped is marked on the recall, once
        self::assertTrue($this->store->markHelpful($run->id, $fact->id, true));
        self::assertFalse($this->store->markHelpful($run->id, $fact->id, false), 'once');
        self::assertFalse($this->store->markHelpful($run->id, $fact->id + 1000, true), 'a fact the run was not given');
        self::assertSame([['fact' => $fact->id, 'version' => $fact->version, 'helpful' => true]], $this->store->receipt($run->id));
        self::assertSame(['recalled' => 1, 'helpful' => 1, 'unhelpful' => 0], $this->store->usage($fact->id));
    }

    /** spec: brief/memory.md#как-сделано-области-и-факты — the versions given over the last turns (runs) of a conversation */
    public function testTheVersionsGivenOverTheLastTurnsAreKnown(): void {
        $fact = $this->store->addFact('owner', \Mercurius\Memory\Domain\Fact::KNOWLEDGE, 'окно неповтора', ['окно'], 0.5, \Mercurius\Memory\Domain\Fact::AGENT, null);
        $row = $this->db->execute('SELECT principal_id FROM conversations WHERE id = ?', [$this->conversationId])->fetchRow() ?? [];
        $principal = isset($row['principal_id']) && is_int($row['principal_id']) ? $row['principal_id'] : 0;
        $runs = new \OpenCCK\Infrastructure\Repository\PostgresRunRepository($this->db);
        $limits = new \OpenCCK\Domain\Agent\RunLimits(30, 1000, 900, 120);

        $this->store->markRecalled($this->conversationId, [$fact->id], [$fact->id => $fact->version]);
        self::assertSame([$fact->id => [$fact->version]], $this->store->recalledVersions($this->conversationId, 3), 'a recall not tied to a run yet is of this turn');
        $first = $runs->start($this->conversationId, $principal, 'openrouter:x/y', $limits);
        self::assertNotNull($first);
        self::assertSame([$fact->id => [$fact->version]], $this->store->recalledVersions($this->conversationId, 3));
        $runs->finish($first->id, \OpenCCK\Domain\Agent\RunStatus::Done, \OpenCCK\Domain\Agent\StopReason::Answered);
        for ($i = 0; $i < 3; $i++) {
            $run = $runs->start($this->conversationId, $principal, 'openrouter:x/y', $limits);
            self::assertNotNull($run);
            $runs->finish($run->id, \OpenCCK\Domain\Agent\RunStatus::Done, \OpenCCK\Domain\Agent\StopReason::Answered);
        }
        self::assertSame([], $this->store->recalledVersions($this->conversationId, 3), 'out of the window of turns');
    }

    protected function tearDown(): void {
        $this->db->close();
        parent::tearDown();
    }
}
