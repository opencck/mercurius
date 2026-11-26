<?php

declare(strict_types=1);

namespace OpenCCK\Test\Support;

use OpenCCK\Domain\Repository\SshHostKeyRepository;
use OpenCCK\Domain\Ssh\SshHostKey;

final class InMemorySshHostKeyRepository implements SshHostKeyRepository {
    /** @var array<int, SshHostKey> */
    public array $rows = [];

    public function of(string $server, string $address): ?SshHostKey {
        foreach ($this->rows as $row) {
            if ($row->server === $server && $row->address === $address) {
                return $row;
            }
        }
        return null;
    }

    public function find(int $id): ?SshHostKey {
        return $this->rows[$id] ?? null;
    }

    public function setPending(string $server, string $address, string $fingerprint): SshHostKey {
        $row = $this->of($server, $address);
        $id = $row === null ? count($this->rows) + 1 : $row->id;
        return $this->rows[$id] = new SshHostKey($id, $server, $address, $row?->hostKey, $fingerprint);
    }

    public function decide(int $id, bool $trust, string $fingerprint): ?SshHostKey {
        $row = $this->rows[$id] ?? null;
        if ($row === null || $row->pending !== $fingerprint) {
            return null;
        }
        return $this->rows[$id] = new SshHostKey($id, $row->server, $row->address, $trust ? $row->pending : $row->hostKey, null);
    }

    public function forget(int $id): ?SshHostKey {
        $row = $this->rows[$id] ?? null;
        return $row === null ? null : $this->rows[$id] = new SshHostKey($id, $row->server, $row->address, null, null);
    }

    public function pin(string $server, string $address, string $hostKey): void {
        $row = $this->of($server, $address);
        $id = $row === null ? count($this->rows) + 1 : $row->id;
        $this->rows[$id] = new SshHostKey($id, $server, $address, $hostKey, null);
    }
}
