<?php

declare(strict_types=1);

namespace OpenCCK\Infrastructure\Config;

use SensitiveParameter;

/**
 * Memory: the key of the fingerprints a forgotten text leaves behind.
 *
 * spec: BRIEF.md#память-слои — forgetting removes the text; a keyed fingerprint keeps it from being stored again
 */
final class MemorySettings {
    private readonly ?string $secret;

    private function __construct(#[SensitiveParameter] ?string $secret) {
        $this->secret = $secret;
    }

    /**
     * @param array<array-key, mixed> $env
     * @throws ConfigException
     */
    public static function fromEnv(array $env): self {
        return new self(Env::raw($env, 'MEMORY_SECRET'));
    }

    /**
     * The fingerprint key; without MEMORY_SECRET it is derived from the given seed (the bot token), so rotating that
     * seed lets forgotten texts be proposed again.
     */
    public function key(#[SensitiveParameter] string $seed): string {
        return $this->secret ?? hash('sha256', 'mercurius-memory:' . $seed);
    }

    public function hasSecret(): bool {
        return $this->secret !== null;
    }

    /** @return array<string, string> */
    public function __debugInfo(): array {
        return ['secret' => $this->secret === null ? 'none' : '***'];
    }
}
