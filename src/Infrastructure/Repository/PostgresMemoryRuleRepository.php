<?php

declare(strict_types=1);

namespace OpenCCK\Infrastructure\Repository;

use Amp\Postgres\PostgresLink;
use OpenCCK\Domain\Repository\MemoryRuleRepository;

final class PostgresMemoryRuleRepository implements MemoryRuleRepository {
    public function __construct(private readonly PostgresLink $db) {}

    public function snapshot(string $file): ?string {
        $row = $this->db->execute('SELECT rules FROM memory_rule_snapshots WHERE file = ?', [$file])->fetchRow();
        $rules = $row['rules'] ?? null;
        return is_string($rules) ? $rules : null;
    }

    public function keep(string $file, string $rules): void {
        $this->db->execute(
            'INSERT INTO memory_rule_snapshots (file, rules) VALUES (?, ?) ON CONFLICT (file) DO UPDATE SET rules = EXCLUDED.rules, updated_at = now()',
            [$file, $rules],
        );
    }

    public function record(int $holder, string $path, string $kind, ?int $projectId, string $before, string $after): int {
        $row = $this->db->execute(
            'INSERT INTO memory_rule_changes (holder, path, kind, project_id, before, after) VALUES (?, ?, ?, ?, ?, ?) RETURNING id',
            [$holder, $path, $kind, $projectId, $before, $after],
        )->fetchRow();
        $id = $row['id'] ?? null;
        return is_int($id) ? $id : 0;
    }

    public function change(int $id): ?array {
        $row = $this->db->execute('SELECT holder, path, kind, project_id, before, after, undone_at IS NOT NULL AS undone FROM memory_rule_changes WHERE id = ?', [$id])->fetchRow();
        if ($row === null) {
            return null;
        }
        $holder = $row['holder'] ?? null;
        $project = $row['project_id'] ?? null;
        $text = static function (string $key) use ($row): string {
            $value = $row[$key] ?? null;
            return is_string($value) ? $value : '';
        };
        return [
            'holder' => is_int($holder) ? $holder : 0,
            'path' => $text('path'),
            'kind' => $text('kind'),
            'projectId' => is_int($project) ? $project : null,
            'before' => $text('before'),
            'after' => $text('after'),
            'undone' => ($row['undone'] ?? false) === true,
        ];
    }

    public function undone(int $id): void {
        $this->db->execute('UPDATE memory_rule_changes SET undone_at = now() WHERE id = ?', [$id]);
    }

    public function forget(): void {
        $this->db->query('DELETE FROM memory_rule_snapshots');
        $this->db->query('DELETE FROM memory_rule_changes');
    }
}
