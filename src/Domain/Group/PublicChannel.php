<?php

declare(strict_types=1);

namespace OpenCCK\Domain\Group;

/**
 * A channel where the bot is (or was) an admin. The rights come from Telegram; a channel where the bot is no longer an
 * admin is unavailable.
 *
 * spec: BRIEF.md#канал-паблик
 */
final class PublicChannel {
    public function __construct(
        public readonly string $channel,
        public readonly string $chatId,
        public readonly string $title,
        public readonly bool $canPost,
        public readonly bool $canEdit,
        public readonly bool $canDelete,
        public readonly bool $available,
    ) {}

    /** "публикация, правка" — what the bot may do there, in words. */
    public function rights(): string {
        $rights = array_keys(array_filter(['публикация' => $this->canPost, 'правка' => $this->canEdit, 'удаление' => $this->canDelete]));
        return $rights === [] ? 'нет прав на посты' : implode(', ', $rights);
    }
}
