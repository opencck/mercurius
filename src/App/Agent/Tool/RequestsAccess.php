<?php

declare(strict_types=1);

namespace OpenCCK\App\Agent\Tool;

/**
 * A tool through which a participant asks the owner for access: it is offered to everyone but the owner, and its card
 * reaches the owner even when the participant may not ask for approvals otherwise. Once it ran (the owner agreed), the
 * granted tool joins the running run at once.
 *
 * spec: BRIEF.md#участник-просит-инструмент-решение-владельца-2026-11-17
 */
interface RequestsAccess {
    /** @param array<string, mixed> $args the tool the call asked for */
    public function grantedTool(array $args): string;
}
