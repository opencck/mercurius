<?php

declare(strict_types=1);

namespace OpenCCK\Test\Support;

use DateTimeImmutable;
use OpenCCK\Domain\Repository\A2aFileRepository;

final class InMemoryA2aFileRepository implements A2aFileRepository {
    /** @var array<string, array{owner: int, task: ?string, name: string, mediaType: string, bytes: string, created: DateTimeImmutable, used: ?DateTimeImmutable}> */
    public array $files = [];

    public function add(int $ownerId, string $name, string $mediaType, string $bytes, ?string $taskId = null): string {
        $id = 'f-' . (count($this->files) + 1);
        $this->files[$id] = ['owner' => $ownerId, 'task' => $taskId, 'name' => $name, 'mediaType' => $mediaType, 'bytes' => $bytes, 'created' => new DateTimeImmutable(), 'used' => null];
        return $id;
    }

    public function take(int $ownerId, string $id): ?array {
        $file = $this->files[$id] ?? null;
        if ($file === null || $file['owner'] !== $ownerId) {
            return null;
        }
        $this->files[$id]['used'] ??= new DateTimeImmutable();
        return ['name' => $file['name'], 'mediaType' => $file['mediaType'], 'bytes' => $file['bytes']];
    }

    public function ofTask(string $taskId): array {
        $found = [];
        foreach ($this->files as $id => $file) {
            if ($file['task'] === $taskId) {
                $found[] = ['id' => $id, 'name' => $file['name'], 'mediaType' => $file['mediaType'], 'size' => strlen($file['bytes'])];
            }
        }
        return $found;
    }

    public function sweep(DateTimeImmutable $unused, DateTimeImmutable $used): int {
        $before = count($this->files);
        $this->files = array_filter($this->files, static fn(array $f): bool => $f['used'] === null ? $f['created'] >= $unused : $f['used'] >= $used);
        return $before - count($this->files);
    }
}
