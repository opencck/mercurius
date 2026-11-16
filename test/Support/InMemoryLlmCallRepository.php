<?php

declare(strict_types=1);

namespace OpenCCK\Test\Support;

use OpenCCK\Domain\Entity\LlmCallRecord;
use OpenCCK\Domain\Repository\LlmCallRepository;

final class InMemoryLlmCallRepository implements LlmCallRepository, \OpenCCK\Domain\Repository\SpendRepository {
    /** @var list<LlmCallRecord> */
    public array $calls = [];

    public function record(LlmCallRecord $call): void {
        $this->calls[] = $call;
    }

    /** Every recorded call counts as recent. */
    public function spentSince(\DateTimeImmutable $since): float {
        return array_sum(array_map(static fn(LlmCallRecord $c): float => $c->costUsd ?? 0.0, $this->calls));
    }
}
