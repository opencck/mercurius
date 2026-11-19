<?php

declare(strict_types=1);

namespace OpenCCK\Test\Support;

use OpenCCK\Domain\Repository\EvalScoreRepository;

final class InMemoryEvalScoreRepository implements EvalScoreRepository {
    /** @var list<array{version: string, model: string, at: int, categories: array<string, array{mean: float, se: float, n: int}>}> */
    public array $records = [];
    private int $clock = 1000;

    public function record(string $version, string $model, array $categories): void {
        $this->records[] = ['version' => $version, 'model' => $model, 'at' => ++$this->clock, 'categories' => $categories];
    }

    public function latest(int $versions): array {
        $by = [];
        foreach (array_reverse($this->records) as $record) {
            $by[$record['version']] ??= $record;
        }
        return array_slice(array_values($by), 0, $versions);
    }
}
