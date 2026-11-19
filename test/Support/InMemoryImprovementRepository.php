<?php

declare(strict_types=1);

namespace OpenCCK\Test\Support;

use OpenCCK\Domain\Repository\ImprovementRepository;

final class InMemoryImprovementRepository implements ImprovementRepository {
    /** @var array<int, array{id: int, kind: string, title: string, body: string, reason: string, key: ?string, status: string, result: ?string}> */
    private array $proposals = [];
    /** @var array<int, array{version: int, text: string, reason: string, active: bool, created: int}> */
    private array $notes = [];

    public function propose(string $kind, string $title, string $body, string $reason, ?string $settingKey): int {
        $id = count($this->proposals) + 1;
        $this->proposals[$id] = ['id' => $id, 'kind' => $kind, 'title' => $title, 'body' => $body, 'reason' => $reason, 'key' => $settingKey, 'status' => 'proposed', 'result' => null];
        return $id;
    }

    public function find(int $id): ?array {
        $p = $this->proposals[$id] ?? null;
        return $p === null ? null : ['id' => $p['id'], 'kind' => $p['kind'], 'title' => $p['title'], 'body' => $p['body'], 'reason' => $p['reason'], 'key' => $p['key'], 'status' => $p['status']];
    }

    public function checking(): array {
        return array_values(array_map(static fn(array $p): int => $p['id'], array_filter($this->proposals, static fn(array $p): bool => $p['status'] === 'checking')));
    }

    public function rejectedTitles(int $limit): array {
        $rejected = array_filter($this->proposals, static fn(array $p): bool => $p['status'] === 'rejected');
        krsort($rejected);
        return array_slice(array_values(array_map(static fn(array $p): string => $p['title'], $rejected)), 0, $limit);
    }

    public function open(): array {
        $found = [];
        foreach ($this->proposals as $id => $p) {
            if ($p['status'] === 'proposed') {
                $found[] = $this->find($id) ?? throw new \LogicException('gone');
            }
        }
        return $found;
    }

    public function move(int $id, array $from, string $to, ?string $result = null): bool {
        if (!isset($this->proposals[$id]) || !in_array($this->proposals[$id]['status'], $from, true)) {
            return false;
        }
        $this->proposals[$id]['status'] = $to;
        $this->proposals[$id]['result'] = $result;
        return true;
    }

    public function addNote(string $text, string $reason): int {
        $version = count($this->notes) + 1;
        $this->notes[$version] = ['version' => $version, 'text' => $text, 'reason' => $reason, 'active' => false, 'created' => time()];
        return $version;
    }

    public function activeNote(): ?array {
        foreach ($this->notes as $note) {
            if ($note['active']) {
                return ['version' => $note['version'], 'text' => $note['text']];
            }
        }
        return null;
    }

    public function activate(?int $version): bool {
        if ($version !== null && !isset($this->notes[$version])) {
            return false;
        }
        foreach (array_keys($this->notes) as $v) {
            $this->notes[$v]['active'] = $v === $version;
        }
        return true;
    }

    public function notes(int $limit): array {
        return array_slice(array_reverse(array_values($this->notes)), 0, $limit);
    }
}
