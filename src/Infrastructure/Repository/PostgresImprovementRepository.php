<?php

declare(strict_types=1);

namespace OpenCCK\Infrastructure\Repository;

use Amp\Postgres\PostgresLink;
use OpenCCK\Domain\Repository\ImprovementRepository;

final class PostgresImprovementRepository implements ImprovementRepository {
    public function __construct(private readonly PostgresLink $db) {}

    public function propose(string $kind, string $title, string $body, string $reason, ?string $settingKey): int {
        $row = $this->db->execute('INSERT INTO improvements (kind, title, body, reason, setting_key) VALUES (?, ?, ?, ?, ?) RETURNING id', [$kind, $title, $body, $reason, $settingKey])->fetchRow();
        return self::int($row ?? [], 'id') ?? 0;
    }

    public function find(int $id): ?array {
        $row = $this->db->execute('SELECT id, kind, title, body, reason, setting_key, status FROM improvements WHERE id = ?', [$id])->fetchRow();
        if ($row === null) {
            return null;
        }
        $key = self::str($row, 'setting_key');
        return [
            'id' => self::int($row, 'id') ?? 0,
            'kind' => self::str($row, 'kind') ?? '',
            'title' => self::str($row, 'title') ?? '',
            'body' => self::str($row, 'body') ?? '',
            'reason' => self::str($row, 'reason') ?? '',
            'key' => $key,
            'status' => self::str($row, 'status') ?? '',
        ];
    }

    public function move(int $id, array $from, string $to, ?string $result = null): bool {
        if ($from === []) {
            return false;
        }
        $marks = implode(', ', array_fill(0, count($from), '?'));
        return $this->db->execute(
            'UPDATE improvements SET status = ?, result = coalesce(?, result), decided_at = now() WHERE id = ? AND status IN (' . $marks . ') RETURNING id',
            [$to, $result, $id, ...$from],
        )->fetchRow() !== null;
    }

    public function addNote(string $text, string $reason): int {
        $row = $this->db->execute('INSERT INTO prompt_notes (text, reason) VALUES (?, ?) RETURNING version', [$text, $reason])->fetchRow();
        return self::int($row ?? [], 'version') ?? 0;
    }

    public function activeNote(): ?array {
        $row = $this->db->query('SELECT version, text FROM prompt_notes WHERE active')->fetchRow();
        return $row === null ? null : ['version' => self::int($row, 'version') ?? 0, 'text' => self::str($row, 'text') ?? ''];
    }

    public function activate(?int $version): bool {
        $tx = $this->db->beginTransaction();
        try {
            if ($version !== null && $tx->execute('SELECT version FROM prompt_notes WHERE version = ?', [$version])->fetchRow() === null) {
                $tx->rollback();
                return false;
            }
            $tx->query('UPDATE prompt_notes SET active = false WHERE active');
            if ($version !== null) {
                $tx->execute('UPDATE prompt_notes SET active = true WHERE version = ?', [$version]);
            }
            $tx->commit();
            return true;
        } catch (\Throwable $e) {
            $tx->rollback();
            throw $e;
        }
    }

    public function notes(int $limit): array {
        $notes = [];
        foreach ($this->db->execute('SELECT version, text, reason, active, extract(epoch FROM created_at)::bigint AS created FROM prompt_notes ORDER BY version DESC LIMIT ?', [$limit]) as $row) {
            $notes[] = [
                'version' => self::int($row, 'version') ?? 0,
                'text' => self::str($row, 'text') ?? '',
                'reason' => self::str($row, 'reason') ?? '',
                'active' => ($row['active'] ?? false) === true,
                'created' => self::int($row, 'created') ?? 0,
            ];
        }
        return $notes;
    }

    /** @param array<string, mixed> $row */
    private static function int(array $row, string $key): ?int {
        return isset($row[$key]) && is_int($row[$key]) ? $row[$key] : null;
    }

    /** @param array<string, mixed> $row */
    private static function str(array $row, string $key): ?string {
        return isset($row[$key]) && is_string($row[$key]) ? $row[$key] : null;
    }
}
