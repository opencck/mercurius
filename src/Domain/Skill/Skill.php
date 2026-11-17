<?php

declare(strict_types=1);

namespace OpenCCK\Domain\Skill;

/**
 * A skill of the agent: a name and a one-line description for the index, a body read on demand.
 *
 * spec: BRIEF.md#скиллы-и-mcp
 * ref: references/hermes-agent/tools/skill_manager_tool.py@e96221fa:L122-L127 — name pattern and limits
 */
final class Skill {
    public const DRAFT = 'draft';
    public const ACTIVE = 'active';
    public const DISABLED = 'disabled';
    public const NAME_PATTERN = '~^[a-z0-9][a-z0-9._-]{0,63}$~';
    public const DESCRIPTION_MAX = 120;
    public const BODY_MAX = 20_000;

    public function __construct(
        public readonly int $id,
        public readonly string $name,
        public readonly string $description,
        public readonly string $body,
        public readonly string $status,
        public readonly string $origin,
        public readonly int $version,
    ) {}
}
