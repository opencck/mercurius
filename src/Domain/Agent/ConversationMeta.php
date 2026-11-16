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
    ) {}
}
