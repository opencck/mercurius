<?php

declare(strict_types=1);

namespace OpenCCK\Domain\Group;

/**
 * A group chat the bot was added to.
 *
 * spec: BRIEF.md#telegram-группы-каналы-и-темы
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
    ) {}
}
