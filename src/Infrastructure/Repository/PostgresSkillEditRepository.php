<?php

declare(strict_types=1);

namespace OpenCCK\Infrastructure\Repository;

use Amp\Postgres\PostgresLink;
use OpenCCK\Domain\Repository\SkillEditRepository;

final class PostgresSkillEditRepository implements SkillEditRepository {
    public function __construct(private readonly PostgresLink $db) {}

    public function record(int $ownerId, string $dir, string $name, ?array $before, string $source): int {
        $row = $this->db->execute(
            'INSERT INTO skill_edits (owner_id, dir, name, before, source) VALUES (?, ?, ?, ?::jsonb, ?) RETURNING id',
            [$ownerId, $dir, $name, $before === null ? null : json_encode((object) $before, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), mb_substr($source, 0, 200)],
        )->fetchRow();
        $id = $row['id'] ?? null;
        return is_int($id) ? $id : 0;
    }

    public function forSkill(int $ownerId, string $dir, int $limit): array {
        $out = [];
        foreach ($this->db->execute(
            'SELECT id, extract(epoch FROM created_at)::bigint AS at, source, before IS NULL AS created FROM skill_edits WHERE owner_id = ? AND dir = ? ORDER BY id DESC LIMIT ?',
            [$ownerId, $dir, max(1, $limit)],
        ) as $row) {
            $id = $row['id'] ?? null;
            $at = $row['at'] ?? null;
            $source = $row['source'] ?? null;
            $out[] = ['id' => is_int($id) ? $id : 0, 'at' => is_int($at) ? $at : 0, 'source' => is_string($source) ? $source : '', 'created' => ($row['created'] ?? false) === true];
        }
        return $out;
    }

    public function find(int $id): ?array {
        $row = $this->db->execute('SELECT id, owner_id, dir, name, before::text AS before FROM skill_edits WHERE id = ?', [$id])->fetchRow();
        if ($row === null) {
            return null;
        }
        $before = null;
        $raw = $row['before'] ?? null;
        if (is_string($raw)) {
            /** @var mixed $decoded */
            $decoded = json_decode($raw, true, 4, JSON_THROW_ON_ERROR);
            $before = [];
            /** @var mixed $text */
            foreach (is_array($decoded) ? $decoded : [] as $path => $text) {
                if (is_string($text)) {
                    $before[(string) $path] = $text;
                }
            }
        }
        $owner = $row['owner_id'] ?? null;
        $dir = $row['dir'] ?? null;
        $name = $row['name'] ?? null;
        return ['id' => $id, 'owner' => is_int($owner) ? $owner : 0, 'dir' => is_string($dir) ? $dir : '', 'name' => is_string($name) ? $name : '', 'before' => $before];
    }
}
