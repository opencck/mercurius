<?php

declare(strict_types=1);

namespace OpenCCK\App\Agent\Delegation;

use OpenCCK\Domain\Agent\StopReason;

/** What a worker did: a failure is a value here, not an exception. */
final class WorkerReport {
    public function __construct(
        public readonly int $index,
        public readonly ?int $runId,
        /** done, failed, limit, stopped */
        public readonly string $status,
        public readonly string $model,
        public readonly int $turns,
        public readonly int $tokens,
        public readonly string $summary,
        public readonly ?string $reason = null,
        /** how the run ended; null when it never started */
        public readonly ?StopReason $stopReason = null,
    ) {}

    /** @return array<string, mixed> what a background delegation keeps in the database */
    public function toArray(): array {
        return [
            'index' => $this->index,
            'run' => $this->runId,
            'status' => $this->status,
            'model' => $this->model,
            'turns' => $this->turns,
            'tokens' => $this->tokens,
            'summary' => $this->summary,
            'reason' => $this->reason,
            'stop' => $this->stopReason?->value,
        ];
    }

    /** @param array<array-key, mixed> $data */
    public static function fromArray(array $data): self {
        $int = static fn(string $key): int => isset($data[$key]) && is_int($data[$key]) ? $data[$key] : 0;
        $text = static fn(string $key): ?string => isset($data[$key]) && is_string($data[$key]) ? $data[$key] : null;
        $stop = $text('stop');
        return new self(
            $int('index'),
            isset($data['run']) && is_int($data['run']) ? $data['run'] : null,
            $text('status') ?? 'failed',
            $text('model') ?? '',
            $int('turns'),
            $int('tokens'),
            $text('summary') ?? '',
            $text('reason'),
            $stop === null ? null : StopReason::tryFrom($stop),
        );
    }
}
