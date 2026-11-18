<?php

declare(strict_types=1);

namespace OpenCCK\Domain\Repository;

/**
 * The skills that an earlier release kept in the base: read once to move them into the owner's folder, then cleared.
 * Skills live in files now; the tables go away with a later migration.
 *
 * spec: BRIEF.md#скиллы-и-mcp — skills are files, not rows of the base
 */
interface OldSkillCatalog {
    /** @return list<array{name: string, description: string, body: string, origin: string, files: array<string, string>}> active skills with the files of their current version */
    public function active(): array;

    /** Forgets every record of the old catalog. */
    public function clear(): void;
}
