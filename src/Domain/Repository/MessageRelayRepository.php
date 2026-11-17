<?php

declare(strict_types=1);

namespace OpenCCK\Domain\Repository;

use DateTimeImmutable;
use OpenCCK\Domain\Relay\MessageRelay;

/**
 * Personal messages waiting for an answer.
 *
 * spec: BRIEF.md#личные-сообщения-участникам
 */
interface MessageRelayRepository {
    public function create(string $channel, string $recipientChatId, int $recipientPrincipalId, int $originConversationId, int $originPrincipalId, ?int $originRunId, string $question): int;

    /** Takes the oldest question still waiting in this private chat since $since, once: it is answered now. */
    public function takeOpen(string $channel, string $recipientChatId, DateTimeImmutable $since): ?MessageRelay;
}
