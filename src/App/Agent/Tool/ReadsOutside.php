<?php

declare(strict_types=1);

namespace OpenCCK\App\Agent\Tool;

/**
 * A tool that brings outside data into the run: web pages, answers of services, mail, other agents. Text written by
 * someone else may carry instructions for the model, so once it is read, saved grants stop applying in that run:
 * every irreversible action goes to the owner's card again.
 *
 * spec: brief/agent-loop.md#подтверждения-и-запреты — outside data cannot spend the owner's grants
 */
interface ReadsOutside {
    /** @param array<string, mixed> $args whether this call reads outside data */
    public function readsOutside(array $args): bool;
}
