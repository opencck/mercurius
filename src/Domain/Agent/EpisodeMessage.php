<?php

declare(strict_types=1);

namespace OpenCCK\Domain\Agent;

use DateTimeImmutable;

/** A message of a past conversation, as history search returns it. */
final class EpisodeMessage {
    public function __construct(
        public readonly int $id,
        public readonly int $conversationId,
        /** user or assistant */
        public readonly string $role,
        public readonly string $content,
        public readonly DateTimeImmutable $createdAt,
        public readonly string $channel,
        public readonly string $chatId,
    ) {}
}
