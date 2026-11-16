<?php

declare(strict_types=1);

namespace OpenCCK\App\Agent\Tool;

final class ValidationOutcome {
    /**
     * @param list<ValidatedCall> $calls in the order the model gave them
     * @param bool $truncated arguments were cut off mid-stream: nothing from this answer may run
     */
    public function __construct(
        public readonly array $calls,
        public readonly bool $truncated,
    ) {}

    public function hasValid(): bool {
        foreach ($this->calls as $call) {
            if ($call->executable()) {
                return true;
            }
        }
        return false;
    }
}
