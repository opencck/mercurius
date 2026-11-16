<?php

declare(strict_types=1);

namespace OpenCCK\App\Service;

use DateTimeImmutable;
use OpenCCK\Domain\Agent\TraceSpan;
use OpenCCK\Domain\Repository\TraceReportRepository;
use OpenCCK\Domain\Repository\TraceRepository;

/**
 * Texts of the owner's reports: spend for a period, the latest runs, the chain of steps of one run.
 *
 * spec: BRIEF.md#трассировка-и-метрики — /usage, /runs, /trace
 */
final class TraceReports {
    public const NO_RUN = 'Такого запуска в трассах нет.';

    public function __construct(
        private readonly TraceReportRepository $figures,
        private readonly TraceRepository $traces,
    ) {}

    public function usage(int $days): string {
        $days = max(1, min(90, $days));
        $since = new DateTimeImmutable(sprintf('-%d days', $days));
        $lines = [sprintf('Расход за %d дн.', $days), '', 'По моделям:'];
        $total = 0.0;
        foreach ($this->figures->usageByModel($since) as $row) {
            $total += $row['cost'];
            $lines[] = sprintf('• %s — %s, вызовов %d, токенов %s / %s', $row['model'], self::usd($row['cost']), $row['calls'], self::n($row['input']), self::n($row['output']));
        }
        $lines[] = '';
        $lines[] = 'По дням:';
        foreach ($this->figures->usageByDay($since) as $row) {
            $lines[] = sprintf('• %s — %s, вызовов %d', $row['day'], self::usd($row['cost']), $row['calls']);
        }
        $costliest = $this->figures->costliestRuns($since, 5);
        if ($costliest !== []) {
            $lines[] = '';
            $lines[] = 'Самые дорогие запуски:';
            foreach ($costliest as $row) {
                $lines[] = sprintf('• #%d — %s, токенов %s', $row['id'], self::usd($row['cost']), self::n($row['tokens']));
            }
        }
        $lines[] = '';
        $lines[] = 'Итого: ' . self::usd($total);
        return implode("\n", $lines);
    }

    public function runs(int $limit = 10): string {
        $rows = $this->figures->recentRuns($limit);
        if ($rows === []) {
            return 'Запусков ещё не было.';
        }
        $lines = ['Последние запуски:'];
        foreach ($rows as $row) {
            $lines[] = sprintf(
                '• #%d %s — %s%s, ходов %d, токенов %s, %s, %s (%s)',
                $row['id'],
                $row['started'],
                $row['status'],
                $row['reason'] === null ? '' : ' (' . $row['reason'] . ')',
                $row['turns'],
                self::n($row['tokens']),
                self::seconds($row['active_ms']),
                self::usd($row['cost']),
                $row['model'],
            );
        }
        $lines[] = '';
        $lines[] = 'Разбор запуска: /trace <номер>';
        return implode("\n", $lines);
    }

    /** The steps of a run in order; null when the run has no trace. */
    public function trace(int $runId): ?string {
        $spans = $this->traces->spans($runId);
        if ($spans === []) {
            return null;
        }
        $lines = [];
        $cost = 0.0;
        foreach ($spans as $span) {
            $cost += $span->costUsd ?? 0.0;
            if ($span->kind === TraceSpan::RUN) {
                array_unshift($lines, sprintf('Запуск #%d — %s, %s, %s', $runId, $span->name, $span->status, $span->durationMs === null ? 'идёт' : self::seconds($span->durationMs)), '');
                continue;
            }
            $line = sprintf('%s %s — %s, %s', $span->kind, $span->name, $span->status, self::seconds($span->durationMs ?? 0));
            if ($span->inputTokens > 0 || $span->outputTokens > 0) {
                $line .= sprintf(', вход %d (кеш %d), выход %d', $span->inputTokens, $span->cacheReadTokens, $span->outputTokens);
            }
            if ($span->costUsd !== null) {
                $line .= ', ' . self::usd($span->costUsd) . ($span->costStatus === TraceSpan::COST_ESTIMATED ? ' (оценка)' : '');
            }
            if ($span->error !== null) {
                $line .= ' — ' . $span->error;
            }
            $lines[] = $line;
        }
        $lines[] = '';
        $lines[] = 'Стоимость шагов: ' . self::usd($cost);
        return implode("\n", $lines);
    }

    private static function usd(float $cost): string {
        return sprintf('$%.4f', $cost);
    }

    private static function n(int $value): string {
        return number_format($value, 0, '.', ' ');
    }

    private static function seconds(int $ms): string {
        return $ms < 10_000 ? sprintf('%.1f с', $ms / 1000) : sprintf('%d с', intdiv($ms, 1000));
    }
}
