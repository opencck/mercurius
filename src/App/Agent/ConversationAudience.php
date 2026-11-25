<?php

declare(strict_types=1);

namespace OpenCCK\App\Agent;

use OpenCCK\Domain\Agent\HistoryScope;
use OpenCCK\Domain\Entity\Member;
use OpenCCK\Domain\Repository\ConversationRepository;
use Mercurius\Memory\App\Audience;

/**
 * Who hears a conversation, for memory: the kind of chat comes from its messages and is kept here; a conversation
 * whose kind is not known (after a restart, before its next message) counts as a group, the narrowest audience.
 *
 * spec: brief/participants.md#контекст-по-месту-и-аудитории
 */
final class ConversationAudience {
    /** @var array<int, bool> conversation => private chat */
    private array $private = [];

    /**
     * @param (\Closure(): list<int>)|null $formerOwners participants who owned the agent before: their conversations stay theirs
     * @param (\Closure(Member, string, string, string): ?int)|null $project the active project of a chat (channel, chat,
     *        topic) the participant may see; null — no projects
     */
    public function __construct(
        private readonly ConversationRepository $conversations,
        private readonly ?\Closure $formerOwners = null,
        private readonly ?\Closure $project = null,
    ) {}

    public function remember(int $conversationId, bool $privateChat): void {
        if (($this->private[$conversationId] ?? null) !== $privateChat) {
            // spec: brief/participants.md#контекст-по-месту-и-аудитории — kept, so a run after a restart knows the kind too
            $this->conversations->setPrivateChat($conversationId, $privateChat);
        }
        $this->private[$conversationId] = $privateChat;
    }

    public function isPrivate(int $conversationId): bool {
        return $this->private[$conversationId] ??= $this->conversations->privateChat($conversationId) ?? false;
    }

    /** @return list<string> memory scopes this conversation may read; [] when the conversation is gone */
    public function scopes(Member $principal, int $conversationId): array {
        $meta = $this->conversations->meta($conversationId);
        if ($meta === null) {
            return [];
        }
        // spec: brief/memory.md#проекты-и-отношение-участника-к-проекту — the facts of the chat's active project
        $project = $this->project === null ? null : ($this->project)($principal, $meta->channel, $meta->chatId, $meta->threadId);
        return Audience::scopes($principal->isOwner(), $principal->id, $meta->channel, $meta->chatId, $this->isPrivate($conversationId), $project);
    }

    /**
     * Past conversations this one may search: the owner in a private chat — all of them, anyone else and any group —
     * this chat's only. Null when the conversation is gone.
     */
    public function history(Member $principal, int $conversationId): ?HistoryScope {
        $meta = $this->conversations->meta($conversationId);
        if ($meta === null) {
            return null;
        }
        return $principal->isOwner() && $this->isPrivate($conversationId) ? HistoryScope::all($this->formerOwners === null ? [] : ($this->formerOwners)()) : HistoryScope::chat($meta->channel, $meta->chatId);
    }
}
