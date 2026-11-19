<?php

declare(strict_types=1);

namespace OpenCCK\Domain\Repository;

/**
 * How skills are used: how many times the agent opened each and when last, and whether the owner pinned it.
 *
 * spec: BRIEF.md#самоулучшение-решено-2026-11-18 — the use of skills for the review and for cleaning the index
 */
interface SkillUsageRepository {
    /** One more opening of the skill now. */
    public function touch(string $name): void;

    /** @return array<string, array{views: int, last: ?int, pinned: bool, off: bool}> */
    public function stats(): array;

    public function pin(string $name, bool $pinned): void;

    /**
     * Turns a skill off (out of the index, its files untouched) or on again.
     *
     * spec: BRIEF.md#скиллы-и-mcp — /skill <name> off
     */
    public function turnOff(string $name, bool $off): void;
}
