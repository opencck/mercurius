<?php

declare(strict_types=1);

namespace OpenCCK\Infrastructure\Config;

use SensitiveParameter;

/**
 * The master key of the vault: SECRETS_KEY, 32 bytes in base64. Empty — the vault is off.
 *
 * spec: BRIEF.md#фаза-7-по-частям — the master key in .env
 */
final class VaultSettings {
    private readonly ?string $key;

    private function __construct(#[SensitiveParameter] ?string $key) {
        $this->key = $key;
    }

    /**
     * @param array<array-key, mixed> $env
     * @throws ConfigException
     */
    public static function fromEnv(array $env): self {
        $raw = Env::raw($env, 'SECRETS_KEY');
        if ($raw === null) {
            return new self(null);
        }
        $key = base64_decode($raw, true);
        if ($key === false || strlen($key) !== 32) {
            throw new ConfigException('SECRETS_KEY must be 32 bytes in base64 (openssl rand -base64 32)');
        }
        return new self($key);
    }

    public function key(): ?string {
        return $this->key;
    }

    /** @return array<string, string> */
    public function __debugInfo(): array {
        return ['key' => $this->key === null ? 'none' : '***'];
    }
}
