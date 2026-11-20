<?php

declare(strict_types=1);

namespace OpenCCK\Domain\Repository;

use DateTimeImmutable;
use OpenCCK\Domain\Peer\A2aTask;
use OpenCCK\Domain\Peer\AgentRequest;
use OpenCCK\Domain\Peer\OutgoingTask;
use OpenCCK\Domain\Peer\Peer;

/**
 * spec: brief/network.md#подключения-агентов — tokens of connecting agents (hashes only), incoming tasks, neighbours, outgoing tasks
 */
interface PeerRepository {
    /** An agent the owner lets in, known by the fingerprint of its key; a new fingerprint replaces the old one. */
    public function allowKey(int $principalId, string $name, string $fingerprint): void;

    public function revokeKey(string $name): bool;

    /** Lets a revoked key in again for the agent it belongs to; its principal, or null when the key is not known at all. */
    public function restoreKey(string $fingerprint): ?int;

    /** The agent the key belongs to; null for an unknown or revoked one. */
    public function principalByFingerprint(string $fingerprint): ?int;

    /** @return list<array{name: string, fingerprint: string, principal: int, revoked: bool}> */
    public function keys(): array;

    /**
     * The request of an agent that proved an unknown key: the one kept for this key, or a new pending one (true);
     * null — $maxPending requests wait already.
     *
     * @return array{AgentRequest, bool}|null
     */
    public function requestAccess(string $fingerprint, string $name, string $url, string $transport, int $maxPending): ?array;

    /** The owner's decision, taken once: allowed or rejected; null — no such pending request. */
    public function decideRequest(int $id, string $status): ?AgentRequest;

    /** Forgets a pending request whose card did not reach the owner: the next knock shows it again. */
    public function dropRequest(int $id): void;

    /** False — this one-time number was seen in the last quarter of an hour: a replay. */
    public function useNonce(string $nonce): bool;

    public function task(string $id): ?A2aTask;

    public function taskByMessage(int $principalId, string $messageId): ?A2aTask;

    /**
     * False — this message of this agent was taken before (a race of two retries).
     *
     * @param list<string> $path instances the task has passed
     */
    public function createTask(string $id, int $principalId, string $contextId, string $messageId, string $state, int $depth = 0, array $path = [], ?int $leaderRun = null, ?string $parentTask = null): bool;

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

    /** Whether the connection to the neighbour is kept; false — no such neighbour. */
    public function setKeep(string $name, bool $keep): bool;

    public function peer(string $name): ?Peer;

    /** @return list<Peer> */
    public function peers(): array;

    public function removePeer(string $name): bool;

    /** @param int|null $leaderRun the run that gave the task, for its trace */
    public function addOutgoing(string $peer, string $remoteId, int $principalId, string $channel, string $chatId, string $task, string $state, ?int $leaderRun = null, string $contextId = ''): OutgoingTask;

    public function findOutgoing(int $id): ?OutgoingTask;

    /** The task was read just now: the next pass reads others first. */
    public function markPolled(int $id): void;

    /**
     * Open tasks given more than $seconds ago.
     *
     * @return list<OutgoingTask>
     */
    public function staleOutgoing(int $seconds, int $limit): array;

    /** What the neighbour reported it spent on the task: its run, tokens and cost in USD. */
    public function recordRemoteSpend(int $id, ?int $remoteRun, int $tokens, ?float $usd): void;

    /** @return list<array{peer: string, remote: string, state: string, run: ?int, tokens: int, usd: ?float}> the tasks a run gave */
    public function outgoingOfRun(int $runId): array;

    /** @return list<OutgoingTask> open tasks, the longest unread first */
    public function openOutgoing(int $limit): array;

    /** Ends an open task; false when it had ended already (a poll and a notice read it at once). */
    public function finishOutgoing(int $id, string $state, ?string $result): bool;
}
