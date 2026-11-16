<?php

declare(strict_types=1);

namespace OpenCCK\Domain\Repository;

use OpenCCK\Domain\Entity\LlmCallRecord;

interface LlmCallRepository {
    public function record(LlmCallRecord $call): void;
}
