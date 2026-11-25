<?php

declare(strict_types=1);

namespace OpenCCK\Test\Support;

use OpenCCK\Domain\Repository\SshKeyRepository;
use OpenCCK\Domain\Ssh\SshKey;

final class InMemorySshKeyRepository implements SshKeyRepository {
    /** @var array<string, SshKey> holder|name => key */
    public array $keys = [];

    public function of(int $holder): array {
        $found = array_values(array_filter($this->keys, static fn(SshKey $k): bool => $k->holder === $holder));
        usort($found, static fn(SshKey $a, SshKey $b): int => strcmp($a->name, $b->name));
        return $found;
    }

    public function find(int $holder, string $name): ?SshKey {
        return $this->keys[$holder . '|' . $name] ?? null;
    }

    public function save(SshKey $key): void {
        $kept = $this->find($key->holder, $key->name);
        $this->keys[$key->holder . '|' . $key->name] = new SshKey($key->holder, $key->name, $key->fingerprint, $key->publicKey, $kept->passphraseSecret ?? $key->passphraseSecret);
    }

    public function setPassphrase(int $holder, string $name, ?string $secret): bool {
        $kept = $this->find($holder, $name);
        if ($kept === null) {
            return false;
        }
        $this->keys[$holder . '|' . $name] = new SshKey($holder, $name, $kept->fingerprint, $kept->publicKey, $secret);
        return true;
    }

    public function remove(int $holder, string $name): bool {
        if ($this->find($holder, $name) === null) {
            return false;
        }
        unset($this->keys[$holder . '|' . $name]);
        return true;
    }
}
