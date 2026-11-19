<?php

declare(strict_types=1);

namespace OpenCCK\Infrastructure\Repository;

use Amp\Postgres\PostgresLink;
use OpenCCK\Domain\Repository\SkillUsageRepository;

final class PostgresSkillUsageRepository implements SkillUsageRepository {
    public function __construct(private readonly PostgresLink $db) {}

    public function touch(string $name): void {
        $this->db->execute(
            'INSERT INTO skill_usage (name, views, last_viewed) VALUES (?, 1, now())
             ON CONFLICT (name) DO UPDATE SET views = skill_usage.views + 1, last_viewed = now()',
            [$name],
        );
    }

    public function stats(): array {
        $out = [];
        foreach ($this->db->query('SELECT name, views, extract(epoch FROM last_viewed)::bigint AS last, pinned, off FROM skill_usage') as $row) {
            $name = $row['name'] ?? null;
            if (!is_string($name)) {
                continue;
            }
            $views = $row['views'] ?? 0;
            $last = $row['last'] ?? null;
            $out[$name] = ['views' => is_int($views) ? $views : 0, 'last' => is_int($last) ? $last : null, 'pinned' => ($row['pinned'] ?? false) === true, 'off' => ($row['off'] ?? false) === true];
        }
        return $out;
    }

    public function turnOff(string $name, bool $off): void {
        $this->db->execute(
            'INSERT INTO skill_usage (name, off) VALUES (?, ?) ON CONFLICT (name) DO UPDATE SET off = excluded.off',
            [$name, $off],
        );
    }

    public function pin(string $name, bool $pinned): void {
        $this->db->execute(
            'INSERT INTO skill_usage (name, pinned) VALUES (?, ?) ON CONFLICT (name) DO UPDATE SET pinned = excluded.pinned',
            [$name, $pinned],
        );
    }
}
