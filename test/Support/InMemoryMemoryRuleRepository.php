<?php

declare(strict_types=1);

namespace OpenCCK\Test\Support;

use OpenCCK\Domain\Repository\MemoryRuleRepository;

final class InMemoryMemoryRuleRepository implements MemoryRuleRepository {
    /** @var array<string, string> */
    public array $snapshots = [];
    /** @var array<int, array{holder: int, path: string, kind: string, projectId: ?int, before: string, after: string, undone: bool}> */
    public array $changes = [];

    public function snapshot(string $file): ?string {
        return $this->snapshots[$file] ?? null;
    }

    public function keep(string $file, string $rules): void {
        $this->snapshots[$file] = $rules;
    }

    public function record(int $holder, string $path, string $kind, ?int $projectId, string $before, string $after): int {
        $id = count($this->changes) + 1;
        $this->changes[$id] = ['holder' => $holder, 'path' => $path, 'kind' => $kind, 'projectId' => $projectId, 'before' => $before, 'after' => $after, 'undone' => false];
        return $id;
    }

    public function change(int $id): ?array {
        return $this->changes[$id] ?? null;
    }

    public function undone(int $id): void {
        if (isset($this->changes[$id])) {
            $this->changes[$id]['undone'] = true;
        }
    }

    public function forget(): void {
        $this->snapshots = [];
        $this->changes = [];
    }
}
