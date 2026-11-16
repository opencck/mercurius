<?php

declare(strict_types=1);

namespace OpenCCK\Test\Support;

use OpenCCK\Domain\Entity\LlmCallRecord;
use OpenCCK\Domain\Repository\LlmCallRepository;

final class InMemoryLlmCallRepository implements LlmCallRepository {
    /** @var list<LlmCallRecord> */
    public array $calls = [];

    public function record(LlmCallRecord $call): void {
        $this->calls[] = $call;
    }
}
