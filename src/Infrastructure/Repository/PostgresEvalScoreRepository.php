<?php

declare(strict_types=1);

namespace OpenCCK\Infrastructure\Repository;

use Amp\Postgres\PostgresLink;
use OpenCCK\Domain\Repository\EvalScoreRepository;

final class PostgresEvalScoreRepository implements EvalScoreRepository {
    public function __construct(private readonly PostgresLink $db) {}

    public function record(string $version, string $model, array $categories): void {
        foreach ($categories as $category => $stats) {
            $this->db->execute(
                'INSERT INTO eval_scores (version, model, category, mean, se, n) VALUES (?, ?, ?, ?, ?, ?)',
                [$version, $model, $category, $stats['mean'], $stats['se'], $stats['n']],
            );
        }
    }

    public function latest(int $versions): array {
        /** @var array<string, array{version: string, model: string, at: int, categories: array<string, array{mean: float, se: float, n: int}>}> $out */
        $out = [];
        foreach ($this->db->execute(
            'WITH runs AS (
                 SELECT version, max(created_at) AS at FROM eval_scores GROUP BY version ORDER BY 2 DESC LIMIT ?
             )
             SELECT DISTINCT ON (s.version, s.category) s.version, s.model, s.category, s.mean, s.se, s.n, extract(epoch FROM r.at)::bigint AS at
             FROM eval_scores s JOIN runs r ON r.version = s.version
             ORDER BY s.version, s.category, s.created_at DESC',
            [$versions],
        ) as $row) {
            $version = self::text($row, 'version');
            $category = self::text($row, 'category');
            if ($version === '' || $category === '') {
                continue;
            }
            $out[$version] ??= ['version' => $version, 'model' => self::text($row, 'model'), 'at' => self::number($row, 'at') === null ? 0 : (int) self::number($row, 'at'), 'categories' => []];
            $out[$version]['categories'][$category] = ['mean' => self::number($row, 'mean') ?? 0.0, 'se' => self::number($row, 'se') ?? 0.0, 'n' => (int) (self::number($row, 'n') ?? 0.0)];
        }
        $list = array_values($out);
        usort($list, static fn(array $a, array $b): int => $b['at'] <=> $a['at']);
        return $list;
    }

    /** @param array<string, mixed> $row */
    private static function text(array $row, string $key): string {
        /** @var mixed $value */
        $value = $row[$key] ?? null;
        return is_string($value) ? $value : '';
    }

    /** @param array<string, mixed> $row */
    private static function number(array $row, string $key): ?float {
        /** @var mixed $value */
        $value = $row[$key] ?? null;
        return is_int($value) || is_float($value) || (is_string($value) && is_numeric($value)) ? (float) $value : null;
    }
}
