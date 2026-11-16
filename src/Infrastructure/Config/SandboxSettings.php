<?php

declare(strict_types=1);

namespace OpenCCK\Infrastructure\Config;

use OpenCCK\Domain\Agent\SandboxLimits;
use SensitiveParameter;

/**
 * Where the sandbox executor is and the owner's share of it. Without SANDBOX_TOKEN the sandbox is off and the
 * file and command tools are not registered.
 *
 * spec: BRIEF.md#песочница — limits from .env
 */
final class SandboxSettings {
    private readonly ?string $token;

    private function __construct(
        public readonly string $url,
        #[SensitiveParameter] ?string $token,
        private readonly SandboxLimits $ownerLimits,
    ) {
        $this->token = $token;
    }

    /**
     * @param array<array-key, mixed> $env
     * @throws ConfigException
     */
    public static function fromEnv(array $env): self {
        $url = Env::raw($env, 'SANDBOX_URL') ?? 'http://sandbox:9000';
        if (!preg_match('#^https?://[^/\s]+$#', $url)) {
            throw ConfigException::invalid('SANDBOX_URL', 'a URL like http://sandbox:9000');
        }
        return new self(
            $url,
            Env::raw($env, 'SANDBOX_TOKEN'),
            new SandboxLimits(
                diskBytes: Env::bytes($env, 'SANDBOX_WORKSPACE_LIMIT', 5 * 1024 ** 3),
                // Well below the container's pids limit, so one participant cannot take every process slot.
                processes: Env::positiveInt($env, 'SANDBOX_COMMAND_PROCESSES', 128, 100_000),
                cpuSeconds: Env::positiveInt($env, 'SANDBOX_COMMAND_CPU_SECONDS', 600, 86_400),
                memoryBytes: Env::bytes($env, 'SANDBOX_COMMAND_MEMORY', 8 * 1024 ** 3),
                concurrentCommands: Env::positiveInt($env, 'SANDBOX_CONCURRENT_COMMANDS', 4, 64),
            ),
        );
    }

    public function token(): ?string {
        return $this->token;
    }

    public function ownerLimits(): SandboxLimits {
        return $this->ownerLimits;
    }

    /** @return array<string, string> */
    public function __debugInfo(): array {
        return ['url' => $this->url, 'token' => $this->token === null ? 'none' : '***'];
    }
}
