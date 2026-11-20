<?php

declare(strict_types=1);

namespace OpenCCK\App\Agent\Tool;

use Amp\Cancellation;
use OpenCCK\Domain\Entity\Principal;

/**
 * A tool that completes its arguments from the place of the talk: what it adds (the mailbox of the project of this
 * chat) is what the card shows, what a saved grant is keyed by and what runs.
 *
 * spec: brief/skills-mcp.md#ящики-участников-и-проектов — the mailbox of a call is chosen before the card
 */
interface PreparesInConversation {
    /**
     * @param array<string, mixed> $args the validated arguments
     * @return array<string, mixed>|string the completed arguments, or why the call cannot run
     */
    public function prepareIn(array $args, Principal $principal, int $conversationId, Cancellation $cancellation): array|string;
}
