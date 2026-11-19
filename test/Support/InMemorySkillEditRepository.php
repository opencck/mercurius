<?php

declare(strict_types=1);

namespace OpenCCK\Test\Support;

use OpenCCK\Domain\Repository\SkillEditRepository;

final class InMemorySkillEditRepository implements SkillEditRepository {
    /** @var array<int, array{id: int, owner: int, dir: string, name: string, before: ?array<string, string>, source: string, at: int}> */
    public array $edits = [];

    public function record(int $ownerId, string $dir, string $name, ?array $before, string $source): int {
        $id = count($this->edits) + 1;
        $this->edits[$id] = ['id' => $id, 'owner' => $ownerId, 'dir' => $dir, 'name' => $name, 'before' => $before, 'source' => $source, 'at' => time()];
        return $id;
    }

    public function forSkill(int $ownerId, string $dir, int $limit): array {
        $out = [];
        foreach (array_reverse($this->edits) as $e) {
            if ($e['owner'] === $ownerId && $e['dir'] === $dir && count($out) < $limit) {
                $out[] = ['id' => $e['id'], 'at' => $e['at'], 'source' => $e['source'], 'created' => $e['before'] === null];
            }
        }
        return $out;
    }

    public function find(int $id): ?array {
        $e = $this->edits[$id] ?? null;
        return $e === null ? null : ['id' => $e['id'], 'owner' => $e['owner'], 'dir' => $e['dir'], 'name' => $e['name'], 'before' => $e['before']];
    }
}
