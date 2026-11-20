<?php

declare(strict_types=1);

namespace OpenCCK\Domain\Project;

/**
 * An offer to hand a project over: it waits for the participant it is offered to.
 *
 * spec: brief/participants.md#доступ-к-проектам-и-передача
 */
final class ProjectTransfer {
    public const PENDING = 'pending';
    public const ACCEPTED = 'accepted';
    public const DECLINED = 'declined';
    public const CANCELLED = 'cancelled';

    public function __construct(
        public readonly int $id,
        public readonly int $projectId,
        /** null — the owner of the agent */
        public readonly ?int $fromId,
        /** null — the owner of the agent */
        public readonly ?int $toId,
        public readonly string $status,
    ) {}
}
