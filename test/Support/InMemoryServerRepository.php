<?php

declare(strict_types=1);

namespace OpenCCK\Test\Support;

use DomainException;
use OpenCCK\Domain\Repository\ServerRepository;
use OpenCCK\Domain\Server\Server;

final class InMemoryServerRepository implements ServerRepository {
    /** @var array<int, Server> */
    public array $servers = [];

    public function add(string $name, string $host, int $port, string $username, ?int $secretId, string $group, ?string $route = null): Server {
        if ($this->byName($name) !== null) {
            throw new DomainException(sprintf('Сервер «%s» уже есть.', $name));
        }
        $id = count($this->servers) + 1;
        return $this->servers[$id] = new Server($id, $name, $host, $port, $username, $secretId, $group, null, null, $route);
    }

    public function find(int $id): ?Server {
        return $this->servers[$id] ?? null;
    }

    public function byName(string $name): ?Server {
        foreach ($this->servers as $server) {
            if (mb_strtolower($server->name) === mb_strtolower($name)) {
                return $server;
            }
        }
        return null;
    }

    public function all(): array {
        return array_values($this->servers);
    }

    public function setPendingHostKey(int $id, string $fingerprint): void {
        $s = $this->servers[$id] ?? null;
        if ($s !== null && $s->hostKey === null) {
            $this->servers[$id] = new Server($s->id, $s->name, $s->host, $s->port, $s->username, $s->secretId, $s->group, null, $fingerprint, $s->route);
        }
    }

    public function decideHostKey(int $id, bool $trust, ?string $fingerprint = null): ?Server {
        $s = $this->servers[$id] ?? null;
        if ($s === null || $s->pendingHostKey === null || ($fingerprint !== null && $fingerprint !== $s->pendingHostKey)) {
            return null;
        }
        return $this->servers[$id] = new Server($s->id, $s->name, $s->host, $s->port, $s->username, $s->secretId, $s->group, $trust ? $s->pendingHostKey : $s->hostKey, null, $s->route);
    }

    public function update(int $id, \OpenCCK\Domain\Server\ServerChanges $changes): ?Server {
        $s = $this->servers[$id] ?? null;
        if ($s === null) {
            return null;
        }
        $other = $changes->name === null ? null : $this->byName($changes->name);
        if ($other !== null && $other->id !== $id) {
            throw new DomainException(sprintf('Сервер «%s» уже есть.', $changes->name ?? ''));
        }
        $forget = $changes->forgetsKey($s);
        return $this->servers[$id] = new Server(
            $id,
            $changes->name ?? $s->name,
            $changes->host ?? $s->host,
            $changes->port ?? $s->port,
            $changes->username ?? $s->username,
            $changes->secretId ?? $s->secretId,
            $changes->group ?? $s->group,
            $forget ? null : $s->hostKey,
            $forget ? null : $s->pendingHostKey,
            $changes->route === null ? $s->route : ($changes->route === '' ? null : $changes->route),
        );
    }

    public function remove(int $id): bool {
        if (!isset($this->servers[$id])) {
            return false;
        }
        unset($this->servers[$id]);
        return true;
    }
}
