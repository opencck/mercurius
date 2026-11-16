<?php

declare(strict_types=1);

namespace OpenCCK\Infrastructure\Config;

use SensitiveParameter;

/**
 * Tracing: how long full prompts and answers are kept, and the token that opens /metrics.
 *
 * spec: BRIEF.md#трассировка-и-метрики
 */
final class TraceSettings {
    private readonly ?string $metricsToken;

    /** @param positive-int $retentionDays */
    private function __construct(
        public readonly int $retentionDays,
        #[SensitiveParameter] ?string $metricsToken,
    ) {
        $this->metricsToken = $metricsToken;
    }

    /**
     * @param array<array-key, mixed> $env
     * @throws ConfigException
     */
    public static function fromEnv(array $env): self {
        return new self(
            Env::positiveInt($env, 'TRACE_RETENTION_DAYS', 14, 365),
            Env::raw($env, 'METRICS_TOKEN'),
        );
    }

    /** Null means /metrics is off. */
    public function metricsToken(): ?string {
        return $this->metricsToken;
    }

    /** @return array<string, int|string> */
    public function __debugInfo(): array {
        return ['retentionDays' => $this->retentionDays, 'metricsToken' => $this->metricsToken === null ? 'none' : '***'];
    }
}
