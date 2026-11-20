<?php

declare(strict_types=1);

namespace OpenCCK\App\Agent\Tool;

use OpenCCK\Domain\Entity\Principal;

/**
 * A tool whose irreversible call is decided by the participant it belongs to, not by the owner of the agent: a letter
 * leaves a mailbox by the word of the one who connected that mailbox. The card goes to that participant's private chat.
 *
 * spec: brief/skills-mcp.md#ящики-участников-и-проектов — the owner of a mailbox confirms what is sent from it
 */
interface DecidedByParticipant {
    /**
     * @param array<string, mixed> $args the prepared arguments
     * @return ?Principal who decides; null — the owner of the agent, as for any other tool
     */
    public function decider(array $args): ?Principal;
}
