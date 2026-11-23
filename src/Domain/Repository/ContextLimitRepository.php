<?php

declare(strict_types=1);

namespace OpenCCK\Domain\Repository;

/**
 * The own limits of a project or of a participant's private chats: tokens a conversation holds in the context and the
 * percent of them at which compaction starts. Null — the agent's setting.
 *
 * spec: brief/agent-loop.md#объём-беседы-и-порог-сжатия
 */
interface ContextLimitRepository {
    public const PROJECT = 'project';
    public const PRINCIPAL = 'principal';

    /** @return array{?int, ?int} tokens and percent */
    public function get(string $kind, int $id): array;

    public function save(string $kind, int $id, ?int $tokens, ?int $percent): void;
}
