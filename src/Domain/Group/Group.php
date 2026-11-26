<?php

declare(strict_types=1);

namespace OpenCCK\Domain\Group;

/**
 * A group chat the bot was added to.
 *
 * spec: brief/channels.md#как-сделано-группы
 */
final class Group {
    public const PENDING = 'pending';
    public const APPROVED = 'approved';
    public const REFUSED = 'refused';
    public const MENTION = 'mention';
    public const FREE = 'free';

    public function __construct(
        public readonly string $channel,
        public readonly string $chatId,
        public readonly string $title,
        public readonly string $status,
        public readonly string $mode,
        /** a forum with topics: projects get topics of their own */
        public readonly bool $forum = false,
        /** tokens a day the free mode may spend; null — the default from GROUP_FREE_DAILY_TOKENS, 0 — no limit */
        public readonly ?int $dailyTokens = null,
        /** the owner's card while the group waits for a decision: its message id */
        public readonly ?string $card = null,
    ) {}
}
