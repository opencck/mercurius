<?php

declare(strict_types=1);

namespace OpenCCK\Domain\Skill;

/** A version of a skill: proposed, accepted by the owner or rejected. */
final class SkillVersion {
    public const PROPOSED = 'proposed';
    public const ACCEPTED = 'accepted';
    public const REJECTED = 'rejected';

    public function __construct(
        public readonly int $id,
        public readonly int $skillId,
        public readonly string $name,
        public readonly int $version,
        public readonly string $description,
        public readonly string $body,
        public readonly string $status,
        public readonly string $reason,
        public readonly ?int $principalId,
    ) {}
}
