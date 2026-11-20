<?php

declare(strict_types=1);

namespace OpenCCK\Domain\Repository;

/**
 * Scores of the evaluation set by category, kept for each version of the notes to the system prompt.
 *
 * spec: brief/self-improvement.md#самоулучшение — scores by category for a version of the prompt, and a report
 *       comparing versions
 */
interface EvalScoreRepository {
    /** @param array<string, array{mean: float, se: float, n: int}> $categories */
    public function record(string $version, string $model, array $categories): void;

    /**
     * The last scores of the most recently scored versions, newest first.
     *
     * @return list<array{version: string, model: string, at: int, categories: array<string, array{mean: float, se: float, n: int}>}>
     */
    public function latest(int $versions): array;
}
