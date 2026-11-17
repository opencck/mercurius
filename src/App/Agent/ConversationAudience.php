<?php

declare(strict_types=1);

namespace OpenCCK\App\Agent;

use OpenCCK\Domain\Agent\HistoryScope;
use OpenCCK\Domain\Entity\Principal;
use OpenCCK\Domain\Repository\ConversationRepository;
use OpenCCK\Memory\App\Audience;

/**
 * Who hears a conversation, for memory: the kind of chat comes from its messages and is kept here; a conversation
 * whose kind is not known (after a restart, before its next message) counts as a group, the narrowest audience.
 *
 * spec: BRIEF.md#контекст-по-месту-и-аудитории
 */
final class ConversationAudience {
    /** @var array<int, bool> conversation => private chat */
    private array $private = [];

    public function __construct(private readonly ConversationRepository $conversations) {}

    public function remember(int $conversationId, bool $privateChat): void {
        $this->private[$conversationId] = $privateChat;
    }

    public function isPrivate(int $conversationId): bool {
        return $this->private[$conversationId] ?? false;
    }

    /** @return list<string> memory scopes this conversation may read; [] when the conversation is gone */
    public function scopes(Principal $principal, int $conversationId): array {
        $meta = $this->conversations->meta($conversationId);
        if ($meta === null) {
            return [];
        }
        return Audience::scopes($principal->isOwner(), $principal->id, $meta->channel, $meta->chatId, $this->isPrivate($conversationId));
    }

    /**
     * Past conversations this one may search: the owner in a private chat — all of them, anyone else and any group —
     * this chat's only. Null when the conversation is gone.
     */
    public function history(Principal $principal, int $conversationId): ?HistoryScope {
        $meta = $this->conversations->meta($conversationId);
        if ($meta === null) {
            return null;
        }
        return $principal->isOwner() && $this->isPrivate($conversationId) ? HistoryScope::all() : HistoryScope::chat($meta->channel, $meta->chatId);
    }
}
