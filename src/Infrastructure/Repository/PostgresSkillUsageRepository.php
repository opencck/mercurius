<?php

declare(strict_types=1);

namespace OpenCCK\Infrastructure\Repository;

use Amp\Postgres\PostgresLink;
use OpenCCK\Domain\Repository\SkillUsageRepository;

final class PostgresSkillUsageRepository implements SkillUsageRepository {
    public function __construct(private readonly PostgresLink $db) {}

    public function touch(string $place, string $name): void {
        $this->claim($place, $name);
        $this->db->execute(
            'INSERT INTO skill_stats (place, name, views, last_viewed) VALUES (?, ?, 1, now())
             ON CONFLICT (place, name) DO UPDATE SET views = skill_stats.views + 1, last_viewed = now()',
            [$place, $name],
        );
    }

    public function created(string $place, string $name): void {
        $this->claim($place, $name);
        $this->db->execute('INSERT INTO skill_stats (place, name) VALUES (?, ?) ON CONFLICT (place, name) DO NOTHING', [$place, $name]);
    }

    public function stats(): array {
        $out = [];
        $rows = $this->db->query('SELECT place, name, views, extract(epoch FROM last_viewed)::bigint AS last, extract(epoch FROM created_at)::bigint AS made, pinned, off FROM skill_stats');
        foreach ($rows as $row) {
            $place = $row['place'] ?? null;
            $name = $row['name'] ?? null;
            if (!is_string($place) || !is_string($name)) {
                continue;
            }
            $views = $row['views'] ?? 0;
            $last = $row['last'] ?? null;
            $made = $row['made'] ?? 0;
            $out[$place][$name] = [
                'views' => is_int($views) ? $views : 0,
                'last' => is_int($last) ? $last : null,
                'created' => is_int($made) ? $made : 0,
                'pinned' => ($row['pinned'] ?? false) === true,
                'off' => ($row['off'] ?? false) === true,
            ];
        }
        return $out;
    }

    public function turnOff(string $place, string $name, bool $off): void {
        $this->claim($place, $name);
        $this->db->execute(
            'INSERT INTO skill_stats (place, name, off) VALUES (?, ?, ?) ON CONFLICT (place, name) DO UPDATE SET off = excluded.off',
            [$place, $name, $off],
        );
    }

    public function pin(string $place, string $name, bool $pinned): void {
        $this->claim($place, $name);
        $this->db->execute(
            'INSERT INTO skill_stats (place, name, pinned) VALUES (?, ?, ?) ON CONFLICT (place, name) DO UPDATE SET pinned = excluded.pinned',
            [$place, $name, $pinned],
        );
    }

    /** A row of the time before places becomes the row of this place, once. */
    private function claim(string $place, string $name): void {
        if ($place === '') {
            return;
        }
        $this->db->execute(
            "WITH old AS (DELETE FROM skill_stats WHERE place = '' AND name = ? RETURNING views, last_viewed, created_at, pinned, off)
             INSERT INTO skill_stats (place, name, views, last_viewed, created_at, pinned, off)
             SELECT ?, ?, views, last_viewed, created_at, pinned, off FROM old
             ON CONFLICT (place, name) DO NOTHING",
            [$name, $place, $name],
        );
    }
}
