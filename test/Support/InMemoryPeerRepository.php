<?php

declare(strict_types=1);

namespace OpenCCK\Test\Support;

use DateTimeImmutable;
use OpenCCK\Domain\Peer\A2aTask;
use OpenCCK\Domain\Peer\AgentRequest;
use OpenCCK\Domain\Peer\OutgoingTask;
use OpenCCK\Domain\Peer\Peer;
use OpenCCK\Domain\Repository\PeerRepository;

final class InMemoryPeerRepository implements PeerRepository {
    /** @var array<int, array{name: string, fingerprint: string, revoked: bool}> */
    public array $keys = [];
    /** @var array<string, true> */
    public array $nonces = [];
    /** @var array<string, A2aTask> */
    public array $tasks = [];
    /** @var array<string, DateTimeImmutable> */
    public array $touched = [];
    /** @var array<string, Peer> */
    public array $peers = [];
    /** @var array<int, OutgoingTask> */
    public array $outgoing = [];
    /** @var array<int, AgentRequest> */
    public array $requests = [];
    private int $nextRequest = 1;

    public function requestAccess(string $fingerprint, string $name, string $url, string $transport, int $maxPending): ?array {
        foreach ($this->requests as $request) {
            if ($request->fingerprint === $fingerprint) {
                return [$request, false];
            }
        }
        if (count(array_filter($this->requests, static fn(AgentRequest $r): bool => $r->status === AgentRequest::PENDING)) >= $maxPending) {
            return null;
        }
        $request = new AgentRequest($this->nextRequest++, $fingerprint, $name, $url, $transport, AgentRequest::PENDING);
        $this->requests[$request->id] = $request;
        return [$request, true];
    }

    public function decideRequest(int $id, string $status): ?AgentRequest {
        $request = $this->requests[$id] ?? null;
        if ($request === null || $request->status !== AgentRequest::PENDING) {
            return null;
        }
        return $this->requests[$id] = new AgentRequest($id, $request->fingerprint, $request->name, $request->url, $request->transport, $status);
    }

    public function dropRequest(int $id): void {
        if (($this->requests[$id] ?? null)?->status === AgentRequest::PENDING) {
            unset($this->requests[$id]);
        }
    }

    public function allowKey(int $principalId, string $name, string $fingerprint): void {
        foreach ($this->keys as $id => $k) {
            if ($id !== $principalId && $k['fingerprint'] === $fingerprint) {
                throw new \RuntimeException('duplicate key value violates unique constraint "agent_keys_fingerprint_key"');
            }
        }
        $this->keys[$principalId] = ['name' => $name, 'fingerprint' => $fingerprint, 'revoked' => false];
    }

    public function revokeKey(string $name): bool {
        foreach ($this->keys as $id => $k) {
            if ($k['name'] === $name && !$k['revoked']) {
                $this->keys[$id]['revoked'] = true;
                return true;
            }
        }
        return false;
    }

    public function restoreKey(string $fingerprint): ?int {
        foreach ($this->keys as $id => $k) {
            if ($k['fingerprint'] === $fingerprint) {
                $this->keys[$id]['revoked'] = false;
                return $id;
            }
        }
        return null;
    }

    public function principalByFingerprint(string $fingerprint): ?int {
        foreach ($this->keys as $id => $k) {
            if ($k['fingerprint'] === $fingerprint && !$k['revoked']) {
                return $id;
            }
        }
        return null;
    }

    public function keys(): array {
        $found = [];
        foreach ($this->keys as $id => $k) {
            $found[] = ['name' => $k['name'], 'fingerprint' => $k['fingerprint'], 'principal' => $id, 'revoked' => $k['revoked']];
        }
        return $found;
    }

    public function useNonce(string $nonce): bool {
        if (isset($this->nonces[$nonce])) {
            return false;
        }
        $this->nonces[$nonce] = true;
        return true;
    }

    public function task(string $id): ?A2aTask {
        return $this->tasks[$id] ?? null;
    }

    public function taskByMessage(int $principalId, string $messageId): ?A2aTask {
        foreach ($this->tasks as $t) {
            if ($t->principalId === $principalId && $t->messageId === $messageId) {
                return $t;
            }
        }
        return null;
    }

    public function createTask(string $id, int $principalId, string $contextId, string $messageId, string $state, int $depth = 0, array $path = [], ?int $leaderRun = null, ?string $parentTask = null): bool {
        if ($this->taskByMessage($principalId, $messageId) !== null) {
            return false;
        }
        $this->tasks[$id] = new A2aTask($id, $principalId, $contextId, $messageId, $state, null, null, $depth, $path, $leaderRun, $parentTask);
        $this->touched[$id] = new DateTimeImmutable();
        return true;
    }

    public function updateTask(string $id, string $state, ?int $runId, ?string $result): void {
        $t = $this->tasks[$id];
        $this->tasks[$id] = new A2aTask($t->id, $t->principalId, $t->contextId, $t->messageId, $state, $runId ?? $t->runId, $result ?? $t->result, $t->depth, $t->path, $t->leaderRun, $t->parentTask);
        $this->touched[$id] = new DateTimeImmutable();
    }

    public function taskByRun(int $runId): ?A2aTask {
        foreach (array_reverse($this->tasks) as $t) {
            if ($t->runId === $runId) {
                return $t;
            }
        }
        return null;
    }

    public function contextTurns(int $principalId, string $contextId): int {
        return count(array_filter($this->tasks, static fn(A2aTask $t): bool => $t->principalId === $principalId && $t->contextId === $contextId));
    }

    public function stale(DateTimeImmutable $before): array {
        return array_values(array_filter($this->tasks, fn(A2aTask $t): bool => in_array($t->state, [A2aTask::SUBMITTED, A2aTask::WORKING], true) && $this->touched[$t->id] < $before));
    }

    /** @param array<array-key, mixed>|null $card */
    public function addPeer(string $name, string $url, string $secret, string $publicKey, ?array $card, string $identity = ''): void {
        $this->peers[$name] = new Peer($name, $url, $secret, $publicKey, $card, $identity, $this->peers[$name]->keep ?? false);
    }

    public function setKeep(string $name, bool $keep): bool {
        $p = $this->peers[$name] ?? null;
        if ($p === null) {
            return false;
        }
        $this->peers[$name] = new Peer($p->name, $p->url, $p->secret, $p->publicKey, $p->card, $p->identity, $keep);
        return true;
    }

    public function setIdentity(string $name, string $identity): bool {
        $p = $this->peers[$name] ?? null;
        if ($p === null) {
            return false;
        }
        $this->peers[$name] = new Peer($p->name, $p->url, $p->secret, $p->publicKey, $p->card, $identity, $p->keep);
        return true;
    }

    public function peer(string $name): ?Peer {
        return $this->peers[$name] ?? null;
    }

    public function peers(): array {
        ksort($this->peers);
        return array_values($this->peers);
    }

    public function removePeer(string $name): bool {
        $found = isset($this->peers[$name]);
        unset($this->peers[$name]);
        return $found;
    }

    /** @var array<string, true> */
    private array $hiddenNames = [];

    public function hidden(): array {
        $names = array_keys($this->hiddenNames);
        sort($names);
        return $names;
    }

    public function setHidden(string $name, bool $hidden): bool {
        $was = isset($this->hiddenNames[$name]);
        if ($hidden) {
            $this->hiddenNames[$name] = true;
        } else {
            unset($this->hiddenNames[$name]);
        }
        return $was !== $hidden;
    }

    /** @var array<int, array{leader: ?int, run: ?int, tokens: int, usd: ?float}> */
    public array $spend = [];

    /** @var array<int, int> when each outgoing task was given; a test sets it back in time */
    public array $outgoingAt = [];
    /** @var array<int, int> when each outgoing task was read last */
    public array $polledAt = [];

    public function addOutgoing(string $peer, string $remoteId, int $principalId, string $channel, string $chatId, string $task, string $state, ?int $leaderRun = null, string $contextId = ''): OutgoingTask {
        $id = count($this->outgoing) + 1;
        $this->spend[$id] = ['leader' => $leaderRun, 'run' => null, 'tokens' => 0, 'usd' => null];
        $this->outgoingAt[$id] = time();
        return $this->outgoing[$id] = new OutgoingTask($id, $peer, $remoteId, $principalId, $channel, $chatId, $task, $state, null, $leaderRun, $contextId);
    }

    public function openOutgoing(int $limit): array {
        $open = array_values(array_filter($this->outgoing, static fn(OutgoingTask $o): bool => in_array($o->state, [A2aTask::SUBMITTED, A2aTask::WORKING], true)));
        usort($open, fn(OutgoingTask $x, OutgoingTask $y): int => [$this->polledAt[$x->id] ?? 0, $x->id] <=> [$this->polledAt[$y->id] ?? 0, $y->id]);
        return array_slice($open, 0, $limit);
    }

    public function findOutgoing(int $id): ?OutgoingTask {
        return $this->outgoing[$id] ?? null;
    }

    public function markPolled(int $id): void {
        $this->polledAt[$id] = (int) round(microtime(true) * 1000.0);
    }

    public function staleOutgoing(int $seconds, int $limit): array {
        $stale = array_filter($this->outgoing, fn(OutgoingTask $o): bool => in_array($o->state, [A2aTask::SUBMITTED, A2aTask::WORKING], true) && ($this->outgoingAt[$o->id] ?? time()) < time() - $seconds);
        return array_slice(array_values($stale), 0, $limit);
    }

    public function recordRemoteSpend(int $id, ?int $remoteRun, int $tokens, ?float $usd): void {
        $this->spend[$id] = ['leader' => $this->spend[$id]['leader'] ?? null, 'run' => $remoteRun, 'tokens' => $tokens, 'usd' => $usd];
    }

    public function outgoingOfRun(int $runId): array {
        $found = [];
        foreach ($this->outgoing as $id => $o) {
            $s = $this->spend[$id] ?? null;
            if ($s !== null && $s['leader'] === $runId) {
                $found[] = ['peer' => $o->peer, 'remote' => $o->remoteId, 'state' => $o->state, 'run' => $s['run'], 'tokens' => $s['tokens'], 'usd' => $s['usd']];
            }
        }
        return $found;
    }

    public function finishOutgoing(int $id, string $state, ?string $result): bool {
        $o = $this->outgoing[$id];
        if (!in_array($o->state, [A2aTask::SUBMITTED, A2aTask::WORKING], true)) {
            return false;
        }
        $this->outgoing[$id] = new OutgoingTask($o->id, $o->peer, $o->remoteId, $o->principalId, $o->channel, $o->chatId, $o->task, $state, $result, $o->leaderRun, $o->contextId);
        return true;
    }
}
