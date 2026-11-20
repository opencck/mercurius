<?php

declare(strict_types=1);

namespace OpenCCK\Domain\Agent;

use DateTimeImmutable;

/**
 * What spend to sum and how to split it: by topic, chat, project, participant, model or day; for [from, to); for one
 * participant and one place (a chat, a topic) or all.
 *
 * spec: brief/operations.md#настройки-агента — spend by topics, projects, participants and periods
 */
final class SpendQuery {
    public const BY = ['topic', 'chat', 'project', 'participant', 'model', 'day'];

    /**
     * @param value-of<self::BY>|string $by
     * @param ?array{string, string, string} $place channel, chat, topic ('' — the whole chat)
     */
    public function __construct(
        public readonly string $by,
        public readonly DateTimeImmutable $from,
        public readonly DateTimeImmutable $to,
        public readonly ?int $principalId = null,
        public readonly ?array $place = null,
    ) {}
}
