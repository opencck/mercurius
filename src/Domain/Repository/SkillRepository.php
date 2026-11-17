<?php

declare(strict_types=1);

namespace OpenCCK\Domain\Repository;

use OpenCCK\Domain\Skill\Skill;
use OpenCCK\Domain\Skill\SkillVersion;

/**
 * spec: BRIEF.md#скиллы-и-mcp — the catalog with every version kept; only an accepted version is ever active
 */
interface SkillRepository {
    public function find(string $name): ?Skill;

    /** @return list<Skill> active ones, by name */
    public function active(): array;

    /** @return list<Skill> */
    public function all(): array;

    /** A new version waits for the owner; a new name starts as a draft. */
    public function propose(string $name, string $description, string $body, string $reason, ?int $principalId, string $origin): SkillVersion;

    public function version(int $id): ?SkillVersion;

    /** Makes a proposed version current and the skill active; null when it was decided already. */
    public function accept(int $versionId): ?Skill;

    public function reject(int $versionId): bool;

    public function setStatus(string $name, string $status): bool;

    /** @return list<SkillVersion> accepted versions, newest first */
    public function history(string $name): array;

    /** Makes an accepted version current again. */
    public function rollback(string $name, int $version): ?Skill;
}
