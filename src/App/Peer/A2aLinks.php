<?php

declare(strict_types=1);

namespace OpenCCK\App\Peer;

/**
 * The live connections between this agent and others: outgoing (this agent connected) and incoming (another agent
 * connected here). A connection goes both ways, so to write to an agent any live connection with it serves, whichever
 * side opened it; a new one is opened only when there is none. The owner sees the list, the agent opens and closes
 * its own connections.
 *
 * spec: BRIEF.md#подключения-агентов-решение-владельца-2026-11-17
 */
final class A2aLinks {
    public const OUT = 'out';
    public const IN = 'in';

    private int $next = 1;
    /** @var array<int, array{direction: string, name: string, fingerprint: string, socket: A2aSocket, opened: float}> */
    private array $links = [];

    /** @param self::OUT|self::IN $direction */
    public function add(string $direction, string $name, string $fingerprint, A2aSocket $socket): int {
        $this->prune();
        $id = $this->next++;
        $this->links[$id] = ['direction' => $direction, 'name' => $name, 'fingerprint' => $fingerprint, 'socket' => $socket, 'opened' => microtime(true)];
        return $id;
    }

    /** A live connection with the agent of this key, an outgoing one first; null — none. */
    public function to(string $fingerprint): ?A2aSocket {
        $this->prune();
        $found = null;
        foreach ($this->links as $link) {
            if ($link['fingerprint'] === $fingerprint) {
                if ($link['direction'] === self::OUT) {
                    return $link['socket'];
                }
                $found ??= $link['socket'];
            }
        }
        return $found;
    }

    /** A live connection with the agent known here by this name (an incoming one is named as its owner let it in). */
    public function named(string $name): ?A2aSocket {
        $this->prune();
        foreach ($this->links as $link) {
            if ($link['name'] === $name) {
                return $link['socket'];
            }
        }
        return null;
    }

    public function close(int $id): bool {
        $link = $this->links[$id] ?? null;
        if ($link === null) {
            return false;
        }
        unset($this->links[$id]);
        $link['socket']->close();
        return true;
    }

    /** @return list<array{id: int, direction: string, name: string, fingerprint: string, opened: float, lastUsed: float, requests: int}> */
    public function all(): array {
        $this->prune();
        $list = [];
        foreach ($this->links as $id => $link) {
            $list[] = ['id' => $id, 'direction' => $link['direction'], 'name' => $link['name'], 'fingerprint' => $link['fingerprint'], 'opened' => $link['opened'], 'lastUsed' => $link['socket']->lastUsed, 'requests' => $link['socket']->requests];
        }
        return $list;
    }

    private function prune(): void {
        foreach ($this->links as $id => $link) {
            if (!$link['socket']->alive()) {
                unset($this->links[$id]);
            }
        }
    }
}
