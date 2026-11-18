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

    public function remove(int $id): bool {
        if (!isset($this->servers[$id])) {
            return false;
        }
        unset($this->servers[$id]);
        return true;
    }
}
