<?php

declare(strict_types=1);

namespace OpenCCK\Infrastructure\Ssh;

use Closure;

/**
 * Connections kept open between commands, one per key (a server with its login and route): a series of commands goes
 * through one connection, its channels shared up to a limit; one that idled past the limit, died or broke is closed.
 *
 * spec: BRIEF.md#фаза-7-по-частям — a pool of SSH connections
 * ref: references/hermes-agent/tools/environments/ssh.py@e96221fa:L174-L180 (_build_ssh_command) — ControlMaster=auto
 *      with ControlPersist=300: commands share one connection that lives on a while after the last; here 90 seconds,
 *      within the pool of the process instead of a control socket
 */
final class ConnectionPool {
    /** @var array<string, PooledConnection> */
    private array $entries = [];
    /** @var Closure(): int */
    private readonly Closure $now;

    /**
     * @param int $idle seconds a connection without commands stays open
     * @param int $max connections kept at most
     * @param int $channels commands one connection carries at once (sshd MaxSessions is 10 by default)
     * @param (Closure(): int)|null $now unix seconds
     */
    public function __construct(
        private readonly int $idle = 90,
        private readonly int $max = 16,
        private readonly int $channels = 8,
        ?Closure $now = null,
    ) {
        $this->now = $now ?? static fn(): int => time();
    }

    /** @param Closure(): PooledConnection $open */
    public function acquire(string $key, Closure $open): PooledConnection {
        $entry = $this->entries[$key] ?? null;
        if ($entry !== null && !$entry->alive()) {
            unset($this->entries[$key]);
            $entry->close();
            $entry = null;
        }
        if ($entry !== null && $entry->users >= $this->channels) {
            $extra = $open();
            $extra->pooled = false;
            return $this->use($extra);
        }
        if ($entry === null) {
            $entry = $open();
            // a fiber may have pooled one for the key while this one was connecting
            $raced = $this->entries[$key] ?? null;
            if ($raced !== null && $raced->alive() && $raced->users < $this->channels) {
                $entry->close();
                $entry = $raced;
            } else {
                $this->entries[$key] = $entry;
                $this->trim($key);
            }
        }
        return $this->use($entry);
    }

    /** @param bool $broken the connection failed: it is closed and not given out again */
    public function release(PooledConnection $connection, bool $broken = false): void {
        $connection->users = max(0, $connection->users - 1);
        $connection->lastUsed = ($this->now)();
        if ($broken || !$connection->pooled) {
            foreach ($this->entries as $key => $entry) {
                if ($entry === $connection) {
                    unset($this->entries[$key]);
                }
            }
            if ($broken || $connection->users === 0) {
                $connection->close();
            }
        }
    }

    /** Closes connections that idled past the limit or died; returns how many. */
    public function sweep(): int {
        $closed = 0;
        foreach ($this->entries as $key => $entry) {
            $idle = $entry->users === 0 && ($this->now)() - $entry->lastUsed > $this->idle;
            if ($idle || ($entry->users === 0 && !$entry->alive())) {
                unset($this->entries[$key]);
                $entry->close();
                $closed++;
            }
        }
        return $closed;
    }

    public function closeAll(): void {
        foreach ($this->entries as $entry) {
            $entry->close();
        }
        $this->entries = [];
    }

    /** @return list<PooledConnection> */
    public function open(): array {
        return array_values($this->entries);
    }

    private function use(PooledConnection $connection): PooledConnection {
        $connection->users++;
        $connection->commands++;
        $connection->lastUsed = ($this->now)();
        return $connection;
    }

    /** Keeps at most $max connections: the least recently used idle ones go first. */
    private function trim(string $keep): void {
        while (count($this->entries) > $this->max) {
            $oldest = null;
            foreach ($this->entries as $key => $entry) {
                if ($key !== $keep && $entry->users === 0 && ($oldest === null || $entry->lastUsed < $this->entries[$oldest]->lastUsed)) {
                    $oldest = $key;
                }
            }
            if ($oldest === null) {
                return;
            }
            $this->entries[$oldest]->close();
            unset($this->entries[$oldest]);
        }
    }
}
