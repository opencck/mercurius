<?php

declare(strict_types=1);

namespace OpenCCK\Test\Eval;

/**
 * Scores by category with their noise, and the comparison with a baseline: a category regresses when its mean falls
 * below the baseline by more than the noise (two standard errors of the difference, at least 0.05).
 *
 * spec: BRIEF.md#границы-самоулучшения — no regression in any category beyond noise
 */
final class EvalReport {
    public const MIN_MARGIN = 0.05;

    /**
     * @param list<array{category: string, score: float, ...}> $results
     * @return array<string, array{mean: float, se: float, n: int}>
     */
    public static function categories(array $results): array {
        $by = [];
        foreach ($results as $r) {
            $by[$r['category']][] = $r['score'];
        }
        ksort($by);
        $out = [];
        foreach ($by as $category => $scores) {
            $n = count($scores);
            $mean = self::sum($scores) / (float) $n;
            $var = $n > 1 ? self::sum(array_map(static fn(float $s): float => ($s - $mean) * ($s - $mean), $scores)) / (float) ($n - 1) : 0.0;
            $out[$category] = ['mean' => round($mean, 4), 'se' => round(sqrt($var / (float) $n), 4), 'n' => $n];
        }
        return $out;
    }

    /**
     * @param array<string, array{mean: float, se: float, n: int}> $now
     * @param array<string, array{mean: float, se: float, n: int}> $base
     * @return list<string> the categories that regressed, with numbers
     */
    public static function regressions(array $now, array $base): array {
        $found = [];
        foreach ($now as $category => $stats) {
            if (!isset($base[$category])) {
                continue;
            }
            $margin = max(self::MIN_MARGIN, 2.0 * sqrt($stats['se'] * $stats['se'] + $base[$category]['se'] * $base[$category]['se']));
            if ($stats['mean'] < $base[$category]['mean'] - $margin) {
                $found[] = sprintf('%s: %.2f → %.2f (порог %.2f)', $category, $base[$category]['mean'], $stats['mean'], $margin);
            }
        }
        return $found;
    }

    /** @param list<float> $values */
    public static function sum(array $values): float {
        $sum = 0.0;
        foreach ($values as $v) {
            $sum += $v;
        }
        return $sum;
    }
}
