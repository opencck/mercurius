<?php

declare(strict_types=1);

namespace OpenCCK\App\Service;

use OpenCCK\Domain\Entity\Member;
use OpenCCK\Domain\Repository\MemberRepository;

/**
 * Who the owners are now and where to reach them. The account from the configuration is only the first one: any person
 * whose role has the permission owner is an owner, so cards, notices and the check of who pressed a button ask here at
 * that moment.
 *
 * spec: brief/participants.md#свои-роли — owners are the people whose role has the permission owner
 */
final class OwnerDirectory {
    /** @var list<int>|null */
    private ?array $former = null;

    public function __construct(
        private readonly MemberRepository $principals,
        private readonly string $channel,
        /** the owner's account from the configuration: the first owner, and the address until the owner is known */
        private readonly string $initialId,
    ) {}

    /** spec: brief/participants.md#свои-роли — read anew each time: a role may make or unmake an owner at any moment */
    public function principal(): ?Member {
        return $this->principals->owner();
    }

    /** @return list<Member> the owners now, by id */
    public function owners(): array {
        return $this->principals->owners();
    }

    /**
     * The private chats of the owners in the channel, by the owners' order; before any owner wrote — the account from
     * the configuration.
     *
     * @return list<string>
     */
    public function chatIds(): array {
        $chats = [];
        foreach ($this->owners() as $owner) {
            $chat = $this->principals->externalId($owner->id, $this->channel);
            if ($chat !== null && !in_array($chat, $chats, true)) {
                $chats[] = $chat;
            }
        }
        return $chats === [] && $this->initialId !== '' ? [$this->initialId] : $chats;
    }

    /** Whether an account is an owner's: their chat in the channel, or `p<id>` of the WebSocket API. */
    public function isOwnerAccount(string $userId): bool {
        if (in_array($userId, $this->chatIds(), true)) {
            return true;
        }
        foreach ($this->owners() as $owner) {
            if ($userId === 'p' . $owner->id) {
                return true;
            }
        }
        return false;
    }

    /** A service built with a fixed account (tests, a single owner) or with the directory. */
    public static function isOwner(self|string $owner, string $userId): bool {
        return is_string($owner) ? $userId === $owner : $owner->isOwnerAccount($userId);
    }

    /** The first owner's account in the channel: their private chat. */
    public function chatId(): string {
        $owner = $this->principal();
        return ($owner === null ? null : $this->principals->externalId($owner->id, $this->channel)) ?? $this->initialId;
    }

    /**
     * Participants who owned the agent before and do not now: their personal stays theirs.
     *
     * @return list<int>
     */
    public function formerOwners(): array {
        return $this->former ??= array_keys($this->principals->formerOwners());
    }

    public function isFormerOwner(?int $principalId): bool {
        return $principalId !== null && in_array($principalId, $this->formerOwners(), true);
    }

    /** After ownership passed on: the next question reads the owner anew. */
    public function forget(): void {
        $this->former = null;
    }

    /**
     * Where a card or a notice for the owner goes: with the directory — every owner (the chat of the owners of
     * OwnersChannel); a service built with a fixed chat (tests) — that chat.
     *
     * spec: brief/participants.md#свои-роли — cards and notices go to every owner
     */
    public static function chat(self|string $owner): string {
        return is_string($owner) ? $owner : OwnersChannel::OWNERS;
    }

    /** The first owner's own chat: for what belongs to one person (a conversation, an identity), not for a notice. */
    public static function first(self|string $owner): string {
        return is_string($owner) ? $owner : $owner->chatId();
    }

    /** How the owner of an account is called: their name, else their number; null — not an owner. */
    public function nameOf(string $userId): ?string {
        foreach ($this->owners() as $owner) {
            if ($userId === 'p' . $owner->id || $this->principals->externalId($owner->id, $this->channel) === $userId) {
                return $owner->displayName ?? ('#' . $owner->id);
            }
        }
        return null;
    }
}
