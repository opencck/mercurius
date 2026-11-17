<?php

declare(strict_types=1);

namespace OpenCCK\Domain\Relay;

/**
 * A personal message the agent sent on someone's request, waiting for its answer: the recipient's next message in
 * their private chat goes back to the conversation that asked.
 *
 * spec: BRIEF.md#личные-сообщения-участникам
 */
final class MessageRelay {
    public function __construct(
        public readonly int $id,
        public readonly string $channel,
        /** the recipient's private chat */
        public readonly string $recipientChatId,
        public readonly int $recipientPrincipalId,
        /** the conversation that asked, and who asked */
        public readonly int $originConversationId,
        public readonly int $originPrincipalId,
        public readonly ?int $originRunId,
        /** what the agent wrote */
        public readonly string $question,
    ) {}
}
