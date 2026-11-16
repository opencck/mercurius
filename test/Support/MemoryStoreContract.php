<?php

declare(strict_types=1);

namespace OpenCCK\Test\Support;

use Amp\PHPUnit\AsyncTestCase;
use OpenCCK\Memory\Domain\Fact;
use OpenCCK\Memory\Domain\MemoryStore;
use OpenCCK\Memory\Domain\Proposal;

/**
 * Behaviour every memory store must have: the in-memory fake locally, PostgreSQL on the server.
 */
abstract class MemoryStoreContract extends AsyncTestCase {
    protected MemoryStore $store;
    protected int $conversationId;
    /** a unique scope per test: the database is shared */
    protected string $scope;

    protected function setUp(): void {
        parent::setUp();
        $this->scope = 'principal:' . random_int(100_000, 999_999_999);
    }

    public function testFactsAreFoundByKeysWithinTheirScope(): void {
        $fact = $this->store->addFact($this->scope, Fact::KNOWLEDGE, 'Сервер pve-01 стоит в Хельсинки', ['сервер', 'pve-01', 'хельсинк'], 0.8, Fact::OWNER_SAID, null);
        $this->store->addFact('chat:' . $this->scope, Fact::KNOWLEDGE, 'Другая область', ['сервер'], 0.8, Fact::OWNER_SAID, null);

        $found = $this->store->candidates([$this->scope], ['pve-01', 'нечто'], 10);

        self::assertSame([$fact->id], array_map(static fn(Fact $f): int => $f->id, $found));
        self::assertSame(['сервер', 'pve-01', 'хельсинк'], $found[0]->keys);
        self::assertTrue($found[0]->active);
        self::assertSame(1, $found[0]->version);
    }

    public function testFrequenciesCountWithinTheScope(): void {
        $this->store->addFact($this->scope, Fact::KNOWLEDGE, 'a', ['сервер', 'диск'], 0.5, Fact::AGENT, null);
        $this->store->addFact($this->scope, Fact::KNOWLEDGE, 'b', ['сервер'], 0.5, Fact::AGENT, null);

        $df = $this->store->frequencies([$this->scope], ['сервер', 'диск', 'нет']);

        self::assertSame(2, $df['сервер']);
        self::assertSame(1, $df['диск']);
        self::assertSame(0, $df['нет'] ?? 0);
        self::assertSame(2, $df['']);
    }

    public function testChangeEndAndForget(): void {
        $fact = $this->store->addFact($this->scope, Fact::KNOWLEDGE, 'старое', ['стар'], 0.5, Fact::AGENT, null);

        $changed = $this->store->changeFact($fact->id, 'новое', ['нов'], 'исправление');
        self::assertNotNull($changed);
        self::assertSame(2, $changed->version);
        self::assertSame('новое', $changed->text);
        self::assertSame([], $this->store->candidates([$this->scope], ['стар'], 10), 'keys follow the text');

        self::assertTrue($this->store->endFact($fact->id, 'больше не так'));
        self::assertSame([], $this->store->candidates([$this->scope], ['нов'], 10), 'an ended fact is not recalled');

        $other = $this->store->addFact($this->scope, Fact::KNOWLEDGE, 'секрет', ['секрет'], 0.5, Fact::AGENT, null);
        self::assertTrue($this->store->forgetFact($other->id, 'fp-' . $this->scope));
        self::assertNull($this->store->fact($other->id));
        self::assertTrue($this->store->forgotten('fp-' . $this->scope));
        self::assertFalse($this->store->forgotten('fp-other-' . $this->scope));
    }

    public function testProposalIsDecidedOnce(): void {
        $proposal = $this->store->propose(Proposal::ADD, null, $this->scope, 'Владелец пьёт кофе без сахара', 'сказал сам', null, null);

        self::assertSame(Proposal::PENDING, $proposal->status);
        self::assertContains($proposal->id, array_map(static fn(Proposal $p): int => $p->id, $this->store->pending()));
        self::assertSame(Proposal::APPROVED, $this->store->decide($proposal->id, Proposal::APPROVED)?->status);
        self::assertNull($this->store->decide($proposal->id, Proposal::REJECTED), 'already decided');
        self::assertNotContains($proposal->id, array_map(static fn(Proposal $p): int => $p->id, $this->store->pending()));
    }

    public function testCoreKeepsTheLatestVersion(): void {
        $before = $this->store->core('summary');
        $this->store->setCore('summary', 'Первая ' . $this->scope);
        $this->store->setCore('summary', 'Вторая ' . $this->scope);

        self::assertSame('Вторая ' . $this->scope, $this->store->core('summary'));
        self::assertNotSame($before, $this->store->core('summary'));
    }

    public function testRecallWindow(): void {
        $a = $this->store->addFact($this->scope, Fact::KNOWLEDGE, 'a', ['a1'], 0.5, Fact::AGENT, null);
        $b = $this->store->addFact($this->scope, Fact::KNOWLEDGE, 'b', ['b1'], 0.5, Fact::AGENT, null);

        $this->store->markRecalled($this->conversationId, [$a->id]);
        $this->store->markRecalled($this->conversationId, [$b->id]);

        self::assertEqualsCanonicalizing([$a->id, $b->id], $this->store->recalled($this->conversationId, 20));
        self::assertSame([$b->id], $this->store->recalled($this->conversationId, 1), 'only the latest recalls');
    }
}
