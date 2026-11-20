<?php

declare(strict_types=1);

namespace OpenCCK\Domain\Repository;

use OpenCCK\Domain\Agent\Experience;
use OpenCCK\Domain\Agent\HistoryScope;

/**
 * spec: brief/memory.md#как-сделано-эпизоды-и-поиск — layer 7, outcomes of delegated parts
 */
interface ExperienceRepository {
    /** @return int the record's id */
    public function add(Experience $experience): int;

    /**
     * Records whose goal has any of the words, best first; no words — the latest. Scope is the orchestrator's chat.
     *
     * @param list<string> $words
     * @return list<Experience>
     */
    public function search(array $words, HistoryScope $scope, int $limit, ?string $model = null): array;

    /** Replaces a text in every goal (a forgotten fact); returns the records changed. */
    public function redact(string $text, string $replacement): int;
}
