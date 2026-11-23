<?php

declare(strict_types=1);

namespace OpenCCK\Infrastructure\Repository;

use Amp\Postgres\PostgresLink;
use OpenCCK\Domain\Repository\ContextLimitRepository;

final class PostgresContextLimitRepository implements ContextLimitRepository {
    public function __construct(private readonly PostgresLink $db) {}

    public function get(string $kind, int $id): array {
        $row = $this->db->execute('SELECT max_tokens, compact_percent FROM context_limits WHERE kind = ? AND ref_id = ?', [$kind, $id])->fetchRow();
        return [
            isset($row['max_tokens']) && is_int($row['max_tokens']) ? $row['max_tokens'] : null,
            isset($row['compact_percent']) && is_int($row['compact_percent']) ? $row['compact_percent'] : null,
        ];
    }

    public function save(string $kind, int $id, ?int $tokens, ?int $percent): void {
        if ($tokens === null && $percent === null) {
            $this->db->execute('DELETE FROM context_limits WHERE kind = ? AND ref_id = ?', [$kind, $id]);
            return;
        }
        $this->db->execute(
            'INSERT INTO context_limits (kind, ref_id, max_tokens, compact_percent) VALUES (?, ?, ?, ?)
             ON CONFLICT (kind, ref_id) DO UPDATE SET max_tokens = EXCLUDED.max_tokens, compact_percent = EXCLUDED.compact_percent, updated_at = now()',
            [$kind, $id, $tokens, $percent],
        );
    }
}
