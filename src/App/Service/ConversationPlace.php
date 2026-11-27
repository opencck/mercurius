<?php

declare(strict_types=1);

namespace OpenCCK\App\Service;

use OpenCCK\Domain\Project\Project;

use function Mercurius\I18n\t;

/**
 * Where a private conversation works: the member's own context, the shared one of the owners, or a project.
 *
 * spec: brief/participants.md#контекст-беседы-в-личном-чате
 */
final class ConversationPlace {
    public const PERSONAL = 'personal';
    public const OWNERS = 'owners';
    public const PROJECT = 'project';

    private function __construct(
        /** personal, owners or project */
        public readonly string $kind,
        public readonly ?Project $project = null,
    ) {}

    public static function personal(): self {
        return new self(self::PERSONAL);
    }

    public static function owners(): self {
        return new self(self::OWNERS);
    }

    public static function project(Project $project): self {
        return new self(self::PROJECT, $project);
    }

    /** The value kept in the conversation: null — the member's own. */
    public function context(): ?string {
        return match ($this->kind) {
            self::OWNERS => self::OWNERS,
            self::PROJECT => 'project:' . ($this->project->id ?? 0),
            default => null,
        };
    }

    /** The first line of each answer outside the member's own context. */
    public function mark(): string {
        return match ($this->kind) {
            self::OWNERS => '🌐 global',
            self::PROJECT => '📁 ' . ($this->project->name ?? ''),
            default => '',
        };
    }

    /** The place in words, for the answers of /context and the tool. */
    public function title(): string {
        return match ($this->kind) {
            self::OWNERS => t('place.name_global'),
            self::PROJECT => sprintf(t('place.name_project'), $this->project->name ?? ''),
            default => t('place.name_personal'),
        };
    }
}
