<?php

declare(strict_types=1);

namespace OpenCCK\Infrastructure\Repository;

use Amp\Postgres\PostgresLink;
use OpenCCK\Domain\Repository\OldSkillCatalog;

final class PostgresOldSkillCatalog implements OldSkillCatalog {
    public function __construct(private readonly PostgresLink $db) {}

    public function active(): array {
        $skills = [];
        foreach ($this->db->query("SELECT s.name, s.description, s.body, s.origin, v.id AS version_id FROM skills s LEFT JOIN skill_versions v ON v.skill_id = s.id AND v.version = s.version WHERE s.status = 'active' ORDER BY s.name") as $row) {
            /** @var array<string, string> $files */
            $files = [];
            $versionId = $row['version_id'] ?? null;
            if (is_int($versionId)) {
                foreach ($this->db->execute('SELECT path, content FROM skill_files WHERE version_id = ?', [$versionId]) as $file) {
                    $path = $file['path'] ?? null;
                    $content = $file['content'] ?? null;
                    if (is_string($path) && is_string($content)) {
                        $files[$path] = $content;
                    }
                }
            }
            $skills[] = ['name' => self::str($row, 'name'), 'description' => self::str($row, 'description'), 'body' => self::str($row, 'body'), 'origin' => self::str($row, 'origin'), 'files' => $files];
        }
        return $skills;
    }

    /** @param array<string, mixed> $row */
    private static function str(array $row, string $key): string {
        return isset($row[$key]) && is_string($row[$key]) ? $row[$key] : '';
    }

    public function clear(): void {
        $this->db->query('DELETE FROM skills');
    }
}
