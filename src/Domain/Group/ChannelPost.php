<?php

declare(strict_types=1);

namespace OpenCCK\Domain\Group;

use DateTimeImmutable;

/** A post of a channel as the bot saw it. Its text came from the outside world. */
final class ChannelPost {
    public function __construct(
        public readonly string $messageId,
        public readonly string $text,
        public readonly DateTimeImmutable $postedAt,
        public readonly ?DateTimeImmutable $editedAt,
    ) {}
}
