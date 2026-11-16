<?php

declare(strict_types=1);

namespace OpenCCK\Test\Support;

use OpenCCK\Memory\Domain\Fact;
use OpenCCK\Memory\Domain\MemoryStore;
use OpenCCK\Memory\Domain\Proposal;

final class InMemoryMemoryStore implements MemoryStore {
    /** @var array<int, Fact> */
    public array $facts = [];
    /** @var array<int, list<string>> fact id => texts of earlier versions */
    public array $versions = [];
    /** @var array<string, true> */
    public array $tombstones = [];
    /** @var array<int, Proposal> */
    public array $proposals = [];
    /** @var array<int, array{string, string}> */
    public array $cards = [];
    /** @var array<string, string> */
    public array $coreTexts = [];
    /** @var list<array{int, int}> conversation, fact */
    public array $recalls = [];
    private int $nextFact = 1;
    private int $nextProposal = 1;

    public function addFact(string $scope, string $category, string $text, array $keys, float $trust, string $provenance, ?int $runId): Fact {
        $id = $this->nextFact++;
        return $this->facts[$id] = new Fact($id, $scope, $category, $text, $keys, $trust, $provenance, true, 1, time());
    }

    public function fact(int $id): ?Fact {
        return $this->facts[$id] ?? null;
    }

    public function candidates(array $scopes, array $keys, int $limit): array {
        $found = array_filter(
            $this->facts,
            static fn(Fact $f): bool => $f->active && in_array($f->scope, $scopes, true) && array_intersect($f->keys, $keys) !== [],
        );
        krsort($found);
        return array_slice(array_values($found), 0, $limit);
    }

    public function frequencies(array $scopes, array $keys): array {
        $active = array_filter($this->facts, static fn(Fact $f): bool => $f->active && in_array($f->scope, $scopes, true));
        $df = ['' => count($active)];
        foreach ($keys as $key) {
            $df[$key] = count(array_filter($active, static fn(Fact $f): bool => in_array($key, $f->keys, true)));
        }
        return $df;
    }

    public function recent(array $scopes, int $limit): array {
        $found = array_filter($this->facts, static fn(Fact $f): bool => $f->active && in_array($f->scope, $scopes, true));
        krsort($found);
        return array_slice(array_values($found), 0, $limit);
    }

    public function changeFact(int $id, string $text, array $keys, string $reason): ?Fact {
        $f = $this->facts[$id] ?? null;
        if ($f === null) {
            return null;
        }
        $this->versions[$id][] = $f->text;
        return $this->facts[$id] = new Fact($id, $f->scope, $f->category, $text, $keys, $f->trust, $f->provenance, $f->active, $f->version + 1, time());
    }

    public function endFact(int $id, string $reason): bool {
        $f = $this->facts[$id] ?? null;
        if ($f === null || !$f->active) {
            return false;
        }
        $this->facts[$id] = new Fact($id, $f->scope, $f->category, $f->text, $f->keys, $f->trust, $f->provenance, false, $f->version, time());
        return true;
    }

    public function forgetFact(int $id, string $fingerprint): bool {
        if (!isset($this->facts[$id])) {
            return false;
        }
        unset($this->facts[$id], $this->versions[$id]);
        $this->tombstones[$fingerprint] = true;
        $this->recalls = array_values(array_filter($this->recalls, static fn(array $r): bool => $r[1] !== $id));
        return true;
    }

    public function forgotten(string $fingerprint): bool {
        return isset($this->tombstones[$fingerprint]);
    }

    public function propose(string $kind, ?int $factId, string $scope, string $text, string $reason, ?int $principalId, ?int $runId): Proposal {
        $id = $this->nextProposal++;
        return $this->proposals[$id] = new Proposal($id, $kind, $factId, $scope, $text, $reason, Proposal::PENDING, $principalId, $runId);
    }

    public function proposal(int $id): ?Proposal {
        return $this->proposals[$id] ?? null;
    }

    public function decide(int $id, string $status): ?Proposal {
        $p = $this->proposals[$id] ?? null;
        if ($p === null || $p->status !== Proposal::PENDING) {
            return null;
        }
        return $this->proposals[$id] = new Proposal($p->id, $p->kind, $p->factId, $p->scope, $p->text, $p->reason, $status, $p->principalId, $p->runId);
    }

    public function setCard(int $id, string $chatId, string $messageId): void {
        $this->cards[$id] = [$chatId, $messageId];
    }

    public function pending(): array {
        return array_values(array_filter($this->proposals, static fn(Proposal $p): bool => $p->status === Proposal::PENDING));
    }

    public function core(string $section): string {
        return $this->coreTexts[$section] ?? '';
    }

    public function setCore(string $section, string $text): void {
        $this->coreTexts[$section] = $text;
    }

    public function markRecalled(int $conversationId, array $factIds): void {
        foreach ($factIds as $id) {
            $this->recalls[] = [$conversationId, $id];
        }
    }

    public function recalled(int $conversationId, int $window): array {
        $mine = array_values(array_filter($this->recalls, static fn(array $r): bool => $r[0] === $conversationId));
        return array_values(array_unique(array_map(static fn(array $r): int => $r[1], array_slice($mine, -$window))));
    }
}
