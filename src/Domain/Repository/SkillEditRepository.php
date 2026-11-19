<?php

declare(strict_types=1);

namespace OpenCCK\Domain\Repository;

/**
 * The journal of skill edits: the folder of a skill before each write, for rolling one edit back.
 *
 * spec: BRIEF.md#скиллы-и-mcp — a journal of skill edits with a rollback of one record
 */
interface SkillEditRepository {
    /**
     * @param array<string, string>|null $before path => text of the folder before the edit; null — there was no skill
     * @return int the number of the edit
     */
    public function record(int $ownerId, string $dir, string $name, ?array $before, string $source): int;

    /**
     * The edits of one skill folder, newest first.
     *
     * @return list<array{id: int, at: int, source: string, created: bool}>
     */
    public function forSkill(int $ownerId, string $dir, int $limit): array;

    /** @return array{id: int, owner: int, dir: string, name: string, before: ?array<string, string>}|null */
    public function find(int $id): ?array;
}
