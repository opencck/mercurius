<?php

declare(strict_types=1);

namespace OpenCCK\App\Agent\Tool;

use Amp\Cancellation;
use OpenCCK\Domain\Agent\ParticipantPolicy;
use OpenCCK\Domain\Entity\Principal;

/**
 * A tool that completes its arguments before the call is saved: what it adds (a file the call names, its hash) is what
 * the owner's card shows, what a saved grant is keyed by and what runs — even after a restart, and even if the file
 * changes after the card. The model's own arguments stay as they were in its history.
 *
 * spec: brief/internet.md#скрипты-браузера-решение-владельца-2026-11-17 — the approved script is the script that runs
 */
interface PreparesCall {
    /**
     * @param array<string, mixed> $args the validated arguments
     * @return array<string, mixed>|string the completed arguments, or why the call cannot run
     */
    public function prepare(array $args, Principal $principal, ParticipantPolicy $policy, Cancellation $cancellation): array|string;
}
