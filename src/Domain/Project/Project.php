<?php

declare(strict_types=1);

namespace OpenCCK\Domain\Project;

/**
 * A project of the owner: its goal, status and card. Memory keeps no second copy: layer 2 is this entity.
 *
 * spec: brief/memory.md#проекты-задачи-и-отношение-участника-к-проекту
 */
final class Project {
    public const ACTIVE = 'active';
    public const PAUSED = 'paused';
    public const DONE = 'done';
    public const STATUSES = [self::ACTIVE, self::PAUSED, self::DONE];
    public const NAME_MAX = 100;
    public const GOAL_MAX = 1000;
    public const CARD_MAX = 2000;

    public function __construct(
        public readonly int $id,
        public readonly string $name,
        public readonly string $goal,
        public readonly string $status,
        public readonly string $card,
        public readonly int $updatedAt,
        /** null — the agent's owner; otherwise the participant the project belongs to */
        public readonly ?int $ownerId = null,
        /** an active skill loaded into the context of the project's chats and forum topics; '' — none */
        public readonly string $skill = '',
    ) {}

    /** The owner of the agent sees every project; a participant only their own. */
    public function visibleTo(\OpenCCK\Domain\Entity\Principal $principal): bool {
        return $principal->isOwner() || $this->ownerId === $principal->id;
    }

    /**
     * How far a participant goes in the project: the owner of the agent and the owner of the project own it, a member
     * has the level they were given, anyone else has none.
     *
     * spec: brief/participants.md#доступ-к-проектам-и-передача
     */
    public function levelOf(\OpenCCK\Domain\Entity\Principal $principal, ?string $given): ?ProjectLevel {
        if ($this->visibleTo($principal)) {
            return ProjectLevel::Owner;
        }
        return $given === null ? null : ProjectLevel::given($given);
    }

    /** The filter for a participant: null — the owner, who sees everything. */
    public static function scopeOf(\OpenCCK\Domain\Entity\Principal $principal): ?int {
        return $principal->isOwner() ? null : $principal->id;
    }
}
