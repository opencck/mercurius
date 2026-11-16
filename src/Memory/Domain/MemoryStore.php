<?php

declare(strict_types=1);

namespace OpenCCK\Memory\Domain;

/**
 * Storage of memory: facts with versions and tombstones, proposals to the owner, the always-in-context layer and the
 * recall log. One interface: these live and change together.
 *
 * spec: BRIEF.md#факты
 */
interface MemoryStore {
    /** @param list<string> $keys */
    public function addFact(string $scope, string $category, string $text, array $keys, float $trust, string $provenance, ?int $runId): Fact;

    public function fact(int $id): ?Fact;

    /**
     * Active facts of the scopes sharing at least one key, newest first.
     *
     * @param list<string> $scopes
     * @param list<string> $keys
     * @return list<Fact>
     */
    public function candidates(array $scopes, array $keys, int $limit): array;

    /**
     * How many active facts of the scopes hold each key, and how many there are in all (key "").
     *
     * @param list<string> $scopes
     * @param list<string> $keys
     * @return array<string, int>
     */
    public function frequencies(array $scopes, array $keys): array;

    /**
     * @param list<string> $scopes
     * @return list<Fact> active facts, newest first
     */
    public function recent(array $scopes, int $limit): array;

    /** @param list<string> $keys a new version; null when the fact is gone */
    public function changeFact(int $id, string $text, array $keys, string $reason): ?Fact;

    /** The fact stops being true; it stays in the history. */
    public function endFact(int $id, string $reason): bool;

    /** The fact and its versions are deleted; only the fingerprint stays. */
    public function forgetFact(int $id, string $fingerprint): bool;

    public function forgotten(string $fingerprint): bool;

    public function propose(string $kind, ?int $factId, string $scope, string $text, string $reason, ?int $principalId, ?int $runId): Proposal;

    public function proposal(int $id): ?Proposal;

    /** Moves a pending proposal to approved or rejected; null when it was decided already. */
    public function decide(int $id, string $status): ?Proposal;

    public function setCard(int $id, string $chatId, string $messageId): void;

    /** @return list<Proposal> */
    public function pending(): array;

    /** The current text of profile or summary; '' when none. */
    public function core(string $section): string;

    public function setCore(string $section, string $text): void;

    /** @param list<int> $factIds */
    public function markRecalled(int $conversationId, array $factIds): void;

    /** @return list<int> facts given in the conversation among its last $window recalls */
    public function recalled(int $conversationId, int $window): array;
}
