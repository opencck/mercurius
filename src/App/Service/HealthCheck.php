<?php

declare(strict_types=1);

namespace OpenCCK\App\Service;

/**
 * One dependency check for the health endpoint. Throws when the dependency is not usable.
 */
interface HealthCheck {
    /** @return non-empty-string */
    public function name(): string;

    public function check(): void;
}
