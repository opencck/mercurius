<?php

declare(strict_types=1);

namespace OpenCCK\Domain\Repository;

/**
 * How skills are used, by place and name: how many times the agent opened each and when last, when it was made,
 * whether the owner pinned it or turned it off. A place is where a skill lies (`builtin`, or the owner and the folder
 * of the skills); rows written before places existed have the place '' until their skill is written again.
 *
 * spec: brief/decisions.md#самоулучшение-решено-2026-11-18 — the use of skills for the review and for cleaning the index
 * spec: brief/skills-mcp.md#скиллы-и-mcp — the key of the use is the place and the name
 */
interface SkillUsageRepository {
    /** One more opening of the skill now. */
    public function touch(string $place, string $name): void;

    /** The skill was made now: its row exists from then, so a skill never opened can still grow stale. */
    public function created(string $place, string $name): void;

    /** @return array<string, array<string, array{views: int, last: ?int, created: int, pinned: bool, off: bool}>> place => name => use */
    public function stats(): array;

    public function pin(string $place, string $name, bool $pinned): void;

    /** Turns a skill off (out of the index, its files untouched) or on again. */
    public function turnOff(string $place, string $name, bool $off): void;
}
