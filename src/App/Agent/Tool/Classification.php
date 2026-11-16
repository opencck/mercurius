<?php

declare(strict_types=1);

namespace OpenCCK\App\Agent\Tool;

use OpenCCK\Domain\Agent\DangerClass;

final class Classification {
    /**
     * @param string|null $key key of a saved grant ("action:target"); null — cannot be granted for good
     * @param string $label what the action is, for the approval card
     */
    public function __construct(
        public readonly DangerClass $danger,
        public readonly ?string $key,
        public readonly string $label,
    ) {}
}
