<?php

declare(strict_types=1);

namespace OpenCCK\App\Agent\Delegation;

/**
 * The text the orchestrator reads: per part — status, model, spend, run and a summary cut to a share of the room.
 *
 * spec: BRIEF.md#часть-1--синхронное-делегирование — report
 * ref: references/hermes-agent/tools/delegate_tool_results.py@e96221fa:L254-L309 (_apply_summary_budget) — a cap on
 *      each summary so the reports do not crowd out the orchestrator's context
 */
final class WorkerReports {
    /** @param list<WorkerReport> $reports */
    public static function text(array $reports): string {
        $cap = min(4000, max(500, intdiv(16000, max(1, count($reports)))));
        $lines = [sprintf('Отчёт воркеров (%d):', count($reports))];
        foreach ($reports as $report) {
            $summary = $report->summary === '' ? '(итога нет)' : $report->summary;
            if (mb_strlen($summary) > $cap) {
                $summary = mb_substr($summary, 0, $cap) . sprintf("\n… [итог обрезан; полный — в запуске #%d, владелец видит его через /trace]", (int) $report->runId);
            }
            $lines[] = '';
            $lines[] = sprintf(
                '### Задача %d — %s%s',
                $report->index,
                $report->status,
                $report->reason !== null && $report->status !== 'done' ? ' (' . $report->reason . ')' : '',
            );
            $lines[] = sprintf('Модель %s, ходов %d, токенов %d%s', $report->model, $report->turns, $report->tokens, $report->runId === null ? '' : ', запуск #' . $report->runId);
            if ($report->verdict !== null) {
                $lines[] = sprintf('Проверка: %s%s', $report->verdict, $report->verdictNote === null || $report->verdictNote === '' ? '' : ' — ' . $report->verdictNote);
            }
            $lines[] = $summary;
        }
        return implode("\n", $lines);
    }
}
