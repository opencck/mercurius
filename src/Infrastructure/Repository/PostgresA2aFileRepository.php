<?php

declare(strict_types=1);

namespace OpenCCK\Infrastructure\Repository;

use Amp\Postgres\PostgresByteA;
use Amp\Postgres\PostgresLink;
use DateTimeImmutable;
use OpenCCK\Domain\Repository\A2aFileRepository;

final class PostgresA2aFileRepository implements A2aFileRepository {
    public function __construct(private readonly PostgresLink $db) {}

    public function add(int $ownerId, string $name, string $mediaType, string $bytes, ?string $taskId = null): string {
        $id = 'f-' . bin2hex(random_bytes(12));
        $this->db->execute(
            'INSERT INTO a2a_files (id, owner_id, task_id, name, media_type, size, bytes) VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$id, $ownerId, $taskId, $name, $mediaType, strlen($bytes), new PostgresByteA($bytes)],
        );
        return $id;
    }

    public function take(int $ownerId, string $id): ?array {
        $row = $this->db->execute(
            'UPDATE a2a_files SET used_at = coalesce(used_at, now()) WHERE id = ? AND owner_id = ? RETURNING name, media_type, bytes',
            [$id, $ownerId],
        )->fetchRow();
        $name = $row['name'] ?? null;
        $type = $row['media_type'] ?? null;
        $bytes = $row['bytes'] ?? null;
        return is_string($name) && is_string($type) && is_string($bytes) ? ['name' => $name, 'mediaType' => $type, 'bytes' => $bytes] : null;
    }

    public function ofTask(string $taskId): array {
        $files = [];
        foreach ($this->db->execute('SELECT id, name, media_type, size FROM a2a_files WHERE task_id = ? ORDER BY created_at, id', [$taskId]) as $row) {
            $id = $row['id'] ?? null;
            $name = $row['name'] ?? null;
            $type = $row['media_type'] ?? null;
            $size = $row['size'] ?? null;
            if (is_string($id) && is_string($name) && is_string($type) && is_int($size)) {
                $files[] = ['id' => $id, 'name' => $name, 'mediaType' => $type, 'size' => $size];
            }
        }
        return $files;
    }

    public function sweep(DateTimeImmutable $unused, DateTimeImmutable $used): int {
        $result = $this->db->execute(
            'DELETE FROM a2a_files WHERE (used_at IS NULL AND created_at < ?) OR used_at < ?',
            [$unused->format('Y-m-d H:i:sP'), $used->format('Y-m-d H:i:sP')],
        );
        return $result->getRowCount() ?? 0;
    }
}
