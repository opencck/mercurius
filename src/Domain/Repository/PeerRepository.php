<?php

declare(strict_types=1);

namespace OpenCCK\Domain\Repository;

use DateTimeImmutable;
use OpenCCK\Domain\Peer\A2aTask;
use OpenCCK\Domain\Peer\OutgoingTask;
use OpenCCK\Domain\Peer\Peer;

/**
 * spec: BRIEF.md#сеть-агентов — tokens of connecting agents (hashes only), incoming tasks, neighbours, outgoing tasks
 */
interface PeerRepository {
    /** An agent the owner lets in, known by the fingerprint of its key; a new fingerprint replaces the old one. */
    public function allowKey(int $principalId, string $name, string $fingerprint): void;

    public function revokeKey(string $name): bool;

    /** The agent the key belongs to; null for an unknown or revoked one. */
    public function principalByFingerprint(string $fingerprint): ?int;

    /** @return list<array{name: string, fingerprint: string, principal: int, revoked: bool}> */
    public function keys(): array;

    /** False — this one-time number was seen in the last quarter of an hour: a replay. */
    public function useNonce(string $nonce): bool;

    public function task(string $id): ?A2aTask;

    public function taskByMessage(int $principalId, string $messageId): ?A2aTask;

    /**
     * False — this message of this agent was taken before (a race of two retries).
     *
     * @param list<string> $path instances the task has passed
     */
    public function createTask(string $id, int $principalId, string $contextId, string $messageId, string $state, int $depth = 0, array $path = []): bool;

    public function taskByRun(int $runId): ?A2aTask;

    public function updateTask(string $id, string $state, ?int $runId, ?string $result): void;

    /** Tasks in one context of one agent: the cap on a ping-pong. */
    public function contextTurns(int $principalId, string $contextId): int;

    /** @return list<A2aTask> tasks still running that were last touched before $before */
    public function stale(DateTimeImmutable $before): array;

    /** @param array<array-key, mixed>|null $card */
    public function addPeer(string $name, string $url, string $secret, string $publicKey, ?array $card, string $identity = ''): void;

    /** The key this agent presents to the neighbour; false — no such neighbour. */
    public function setIdentity(string $name, string $identity): bool;

    public function peer(string $name): ?Peer;

    /** @return list<Peer> */
    public function peers(): array;

    public function removePeer(string $name): bool;

    public function addOutgoing(string $peer, string $remoteId, int $principalId, string $channel, string $chatId, string $task, string $state): OutgoingTask;

    /** @return list<OutgoingTask> */
    public function openOutgoing(int $limit): array;

    public function finishOutgoing(int $id, string $state, ?string $result): void;
}
