<?php

declare(strict_types=1);

namespace OpenCCK\Test\Support;

use OpenCCK\Domain\Repository\ProjectPrefRepository;

final class InMemoryProjectPrefRepository implements ProjectPrefRepository {
    /** @var array<int, array{timezone: ?string, quiet: ?string}> */
    public array $prefs = [];

    public function get(int $projectId): array {
        return $this->prefs[$projectId] ?? ['timezone' => null, 'quiet' => null];
    }

    public function save(int $projectId, ?string $timezone, ?string $quiet): void {
        $this->prefs[$projectId] = ['timezone' => $timezone, 'quiet' => $quiet];
    }
}
