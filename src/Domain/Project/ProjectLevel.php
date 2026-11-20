<?php

declare(strict_types=1);

namespace OpenCCK\Domain\Project;

/**
 * How far a participant goes in a project: read it, change it, give it to others, or own it.
 *
 * spec: brief/participants.md#доступ-к-проектам-и-передача
 */
enum ProjectLevel: int {
    /** sees the card, the tasks and the skills of the project */
    case Read = 1;
    /** also changes the card and the tasks, writes skills and sets jobs of the project */
    case Write = 2;
    /** also gives and takes back access */
    case Full = 3;
    /** also hands the project over */
    case Owner = 4;

    /** The levels an owner gives to others. */
    public const GIVEN = ['read', 'write', 'full'];

    public static function given(string $name): ?self {
        return match ($name) {
            'read' => self::Read,
            'write' => self::Write,
            'full' => self::Full,
            default => null,
        };
    }

    public function atLeast(self $level): bool {
        return $this->value >= $level->value;
    }

    public function label(): string {
        return match ($this) {
            self::Read => 'read',
            self::Write => 'write',
            self::Full => 'full',
            self::Owner => 'owner',
        };
    }
}
