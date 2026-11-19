<?php

declare(strict_types=1);

namespace OpenCCK\App\Service;

use OpenCCK\Domain\Entity\Principal;
use OpenCCK\Domain\Repository\PrincipalRepository;

/**
 * Who the owner is now and where to reach them. The owner from the configuration is only the first one: ownership may
 * pass to another participant, so cards, notices and the check of who pressed a button ask here at that moment.
 *
 * spec: BRIEF.md#несколько-пользователей — the owner passes ownership on; the first owner comes from the configuration
 */
final class OwnerDirectory {
    private ?Principal $owner = null;

    public function __construct(
        private readonly PrincipalRepository $principals,
        private readonly string $channel,
        /** the owner's account from the configuration: the first owner, and the address until the owner is known */
        private readonly string $initialId,
    ) {}

    public function principal(): ?Principal {
        return $this->owner ??= $this->principals->owner();
    }

    /** The owner's account in the channel: their private chat. */
    public function chatId(): string {
        $owner = $this->principal();
        return ($owner === null ? null : $this->principals->externalId($owner->id, $this->channel)) ?? $this->initialId;
    }

    /** After ownership passed on: the next question reads the owner anew. */
    public function forget(): void {
        $this->owner = null;
    }

    /** A service built with a fixed chat (tests, a single owner) or with the directory. */
    public static function chat(self|string $owner): string {
        return is_string($owner) ? $owner : $owner->chatId();
    }
}
