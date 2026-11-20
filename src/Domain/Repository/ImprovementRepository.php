<?php

declare(strict_types=1);

namespace OpenCCK\Domain\Repository;

/**
 * The agent's proposals of improvements and the versions of the notes to its system prompt.
 *
 * spec: brief/self-improvement.md#самоулучшение
 */
interface ImprovementRepository {
    public function propose(string $kind, string $title, string $body, string $reason, ?string $settingKey): int;

    /** @return array{id: int, kind: string, title: string, body: string, reason: string, key: ?string, status: string}|null */
    public function find(int $id): ?array;

    /**
     * Moves a proposal from one status to another; false when it was not in $from (pressed twice, decided already).
     *
     * @param list<string> $from
     */
    public function move(int $id, array $from, string $to, ?string $result = null): bool;

    /** @return list<array{id: int, kind: string, title: string, body: string, reason: string, key: ?string, status: string}> proposals still waiting for the owner, the oldest first */
    public function open(): array;

    /** @return list<int> proposals left in checking (a restart in the middle of the gate) */
    public function checking(): array;

    /** @return list<string> titles of the latest proposals the owner rejected, the newest first */
    public function rejectedTitles(int $limit): array;

    public function addNote(string $text, string $reason): int;

    /** @return array{version: int, text: string}|null */
    public function activeNote(): ?array;

    /** Makes a version the active one, or none with null; false when there is no such version. */
    public function activate(?int $version): bool;

    /** @return list<array{version: int, text: string, reason: string, active: bool, created: int}> the newest first */
    public function notes(int $limit): array;
}
