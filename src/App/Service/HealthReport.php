<?php

declare(strict_types=1);

namespace OpenCCK\App\Service;

final class HealthReport {
    /** @param array<string, 'ok'|'fail'|'timeout'> $checks */
    public function __construct(
        public readonly bool $healthy,
        public readonly array $checks,
    ) {}
}
