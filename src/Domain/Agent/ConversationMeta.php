<?php

declare(strict_types=1);

namespace OpenCCK\Domain\Agent;

/**
 * Where a conversation lives and what was fixed when it opened.
 */
final class ConversationMeta {
    public function __construct(
        public readonly int $id,
        public readonly int $principalId,
        public readonly string $channel,
        public readonly string $chatId,
        public readonly ?string $systemPrompt,
        public readonly ?string $model,
        /** the forum topic; '' — a private chat, an ordinary group or the General topic */
        public readonly string $threadId = '',
        /**
         * spec: brief/participants.md#контекст-беседы-в-личном-чате — null — the member's own, 'owners' or 'project:<id>'
         */
        public readonly ?string $context = null,
    ) {}
}
