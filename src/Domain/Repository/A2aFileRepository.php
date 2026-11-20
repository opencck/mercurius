<?php

declare(strict_types=1);

namespace OpenCCK\Domain\Repository;

use DateTimeImmutable;

/**
 * Files between agents: each is given only to the agent it is meant for.
 *
 * spec: brief/internet.md#между-агентами
 */
interface A2aFileRepository {
    /** Keeps a file for the agent $ownerId (and, for an answer, the task it belongs to); returns its number. */
    public function add(int $ownerId, string $name, string $mediaType, string $bytes, ?string $taskId = null): string;

    /**
     * The file, when it is meant for $ownerId; marks it used.
     *
     * @return array{name: string, mediaType: string, bytes: string}|null
     */
    public function take(int $ownerId, string $id): ?array;

    /** @return list<array{id: string, name: string, mediaType: string, size: int}> the files of the answer of a task */
    public function ofTask(string $taskId): array;

    /** Removes files unused since $unused and used since $used; returns how many. */
    public function sweep(DateTimeImmutable $unused, DateTimeImmutable $used): int;
}
