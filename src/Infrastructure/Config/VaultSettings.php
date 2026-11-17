<?php

declare(strict_types=1);

namespace OpenCCK\Infrastructure\Config;

use SensitiveParameter;

/**
 * The master key of the vault: SECRETS_KEY, 32 bytes in base64 (empty — the vault and SSH are off); groups of servers
 * where every command waits for the owner: SSH_CONFIRM_GROUPS, comma-separated.
 *
 * spec: BRIEF.md#фаза-7-по-частям — the master key in .env, a policy by groups of servers
 */
final class VaultSettings {
    private readonly ?string $key;

    /** @param list<string> $confirmGroups */
    private function __construct(
        #[SensitiveParameter] ?string $key,
        public readonly array $confirmGroups = [],
    ) {
        $this->key = $key;
    }

    /**
     * @param array<array-key, mixed> $env
     * @throws ConfigException
     */
    public static function fromEnv(array $env): self {
        $groups = array_values(array_filter(array_map('trim', explode(',', Env::raw($env, 'SSH_CONFIRM_GROUPS') ?? '')), static fn(string $g): bool => $g !== ''));
        $raw = Env::raw($env, 'SECRETS_KEY');
        if ($raw === null) {
            return new self(null, $groups);
        }
        $key = base64_decode($raw, true);
        if ($key === false || strlen($key) !== 32) {
            throw new ConfigException('SECRETS_KEY must be 32 bytes in base64 (openssl rand -base64 32)');
        }
        return new self($key, $groups);
    }

    public function key(): ?string {
        return $this->key;
    }

    /** @return array<string, string> */
    public function __debugInfo(): array {
        return ['key' => $this->key === null ? 'none' : '***'];
    }
}
