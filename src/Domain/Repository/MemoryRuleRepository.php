<?php

declare(strict_types=1);

namespace OpenCCK\Domain\Repository;

/**
 * The section «Правила» of each MEMORY.md as last seen, and its changes with the text before them: kept only while the
 * owner has the notice of changes on.
 *
 * spec: brief/memory.md#сообщение-об-изменении-правил
 */
interface MemoryRuleRepository {
    /** The rules last seen in a file; null — never seen (or forgotten since). */
    public function snapshot(string $file): ?string;

    public function keep(string $file, string $rules): void;

    /** @return int the number of the change */
    public function record(int $holder, string $path, string $kind, ?int $projectId, string $before, string $after): int;

    /** @return array{holder: int, path: string, kind: string, projectId: ?int, before: string, after: string, undone: bool}|null */
    public function change(int $id): ?array;

    public function undone(int $id): void;

    /** With the notice off nothing is kept: snapshots and changes go. */
    public function forget(): void;
}
