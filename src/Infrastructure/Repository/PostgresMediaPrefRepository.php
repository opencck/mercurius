<?php

declare(strict_types=1);

namespace OpenCCK\Infrastructure\Repository;

use Amp\Postgres\PostgresLink;
use OpenCCK\Domain\Media\MediaPref;
use OpenCCK\Domain\Repository\MediaPrefRepository;

final class PostgresMediaPrefRepository implements MediaPrefRepository {
    public function __construct(private readonly PostgresLink $db) {}

    public function of(int $principalId): array {
        $prefs = [];
        foreach ($this->db->execute('SELECT kind, mode, model, rules FROM media_prefs WHERE principal_id = ?', [$principalId]) as $row) {
            $kind = $row['kind'] ?? null;
            $mode = $row['mode'] ?? null;
            $model = $row['model'] ?? null;
            $rules = $row['rules'] ?? null;
            if (is_string($kind)) {
                $prefs[$kind] = new MediaPref($kind, is_string($mode) ? $mode : null, is_string($model) ? $model : null, is_string($rules) ? $rules : '');
            }
        }
        return $prefs;
    }

    public function save(int $principalId, MediaPref $pref): void {
        if ($pref->mode === null && $pref->model === null && $pref->rules === '') {
            $this->db->execute('DELETE FROM media_prefs WHERE principal_id = ? AND kind = ?', [$principalId, $pref->kind]);
            return;
        }
        $this->db->execute(
            'INSERT INTO media_prefs (principal_id, kind, mode, model, rules) VALUES (?, ?, ?, ?, ?)
             ON CONFLICT (principal_id, kind) DO UPDATE SET mode = excluded.mode, model = excluded.model, rules = excluded.rules, updated_at = now()',
            [$principalId, $pref->kind, $pref->mode, $pref->model, $pref->rules],
        );
    }
}
