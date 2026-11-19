<?php

declare(strict_types=1);

namespace OpenCCK\App\Agent\Tool;

/**
 * A tool whose call can be refused before the owner sees a card: a card that can only end in a refusal (granting rights
 * outside the owner's private chat) is not shown, the model gets the reason at once.
 *
 * spec: BRIEF.md#решения-по-доработкам-2026-11-18 — granting rights is a card only in the owner's private chat
 */
interface ChecksBeforeApproval {
    /** @param array<string, mixed> $args */
    public function refuseEarly(array $args, ToolContext $context): ?ToolResult;
}
