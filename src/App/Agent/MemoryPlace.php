<?php

declare(strict_types=1);

namespace OpenCCK\App\Agent;

/**
 * Where the MEMORY.md of a context lies: in whose sandbox folder, at what path, and whose it is — a person's own, a
 * project's or a place's (a group, a channel, a forum topic without a project).
 *
 * spec: brief/memory.md#файл-памяти-места
 */
final class MemoryPlace {
    public const PERSON = 'person';
    public const PROJECT = 'project';
    public const PLACE = 'place';

    /** @param self::PERSON|self::PROJECT|self::PLACE $kind */
    public function __construct(
        /** the participant whose sandbox folder holds the file */
        public readonly int $holder,
        public readonly string $path,
        public readonly string $kind,
        /** the project, for a project's file */
        public readonly ?int $project = null,
    ) {}

    public function shared(): bool {
        return $this->kind !== self::PERSON;
    }

    /** A key of the file for what is kept about it: the holder and the path. */
    public function key(): string {
        return $this->holder . ':' . $this->path;
    }
}
