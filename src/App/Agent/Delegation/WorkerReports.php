<?php

declare(strict_types=1);

namespace OpenCCK\App\Agent\Delegation;

use function Mercurius\I18n\t;

/**
 * The text the orchestrator reads: per part — status, model, spend, run and a summary cut to a share of the room.
 *
 * spec: brief/orchestration.md#часть-1--синхронное-делегирование — report
 * ref: references/hermes-agent/tools/delegate_tool_results.py@e96221fa:L254-L309 (_apply_summary_budget) — a cap on
 *      each summary so the reports do not crowd out the orchestrator's context
 */
final class WorkerReports {
    /** the share of a part when the room left in the orchestrator's window is not known */
    private const ROOM_UNKNOWN = 16000;

    /**
     * @param list<WorkerReport> $reports
     * @param int|null $room characters the orchestrator's window has left for these reports; null — not known
     */
    public static function text(array $reports, ?int $room = null): string {
        // spec: brief/orchestration.md#часть-1--синхронное-делегирование — min(4000, free room ÷ number of parts), at least 500
        $cap = min(4000, max(500, intdiv(max(0, $room ?? self::ROOM_UNKNOWN), max(1, count($reports)))));
        $lines = [sprintf(t('delegation.worker_reports.otchet_vorkerov'), count($reports))];
        foreach ($reports as $report) {
            $summary = $report->summary === '' ? t('delegation.worker_reports.itoga_net') : $report->summary;
            if (mb_strlen($summary) > $cap) {
                // The head says what was found, the tail how it ended; the middle is one call away.
                $head = (int) floor((float) $cap * 0.7);
                $tail = $cap - $head;
                $summary = mb_substr($summary, 0, $head)
                    . sprintf(t('delegation.worker_reports.itog_obrezan_polnyy_run'), WorkerReportTool::NAME, (int) $report->runId)
                    . mb_substr($summary, -$tail);
            }
            $lines[] = '';
            $lines[] = sprintf(
                t('delegation.worker_reports.zadacha'),
                $report->index,
                $report->status,
                $report->reason !== null && $report->status !== 'done' ? ' (' . $report->reason . ')' : '',
            );
            $lines[] = sprintf(t('delegation.worker_reports.model_hodov_tokenov'), $report->model, $report->turns, $report->tokens, $report->runId === null ? '' : t('delegation.worker_reports.zapusk') . $report->runId);
            // spec: brief/orchestration.md#схема-ответа-воркера — handed over either way, but marked
            if ($report->schemaErrors !== null) {
                $lines[] = $report->schemaErrors === []
                    ? t('delegation.worker_reports.schema_ok')
                    : t('delegation.worker_reports.schema_failed') . ' ' . implode('; ', $report->schemaErrors);
            }
            if ($report->verdict !== null) {
                $lines[] = sprintf(t('delegation.worker_reports.proverka'), $report->verdict, $report->verdictNote === null || $report->verdictNote === '' ? '' : ' — ' . $report->verdictNote);
            }
            $lines[] = $summary;
        }
        return implode("\n", $lines);
    }
}
