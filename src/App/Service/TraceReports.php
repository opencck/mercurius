<?php

declare(strict_types=1);

namespace OpenCCK\App\Service;

use DateTimeImmutable;
use OpenCCK\Domain\Agent\TraceSpan;
use OpenCCK\Domain\Repository\TraceReportRepository;
use OpenCCK\Domain\Repository\TraceRepository;

use function Mercurius\I18n\t;

/**
 * Texts of the owner's reports: spend for a period, the latest runs, the chain of steps of one run.
 *
 * spec: BRIEF.md#трассировка-и-метрики — /usage, /runs, /trace
 * spec: BRIEF.md#часть-4--проверяющий-и-данные-трасс — /delegation
 */
final class TraceReports {
    public const NO_RUN = 'service.trace_reports.no_run';

    public function __construct(
        private readonly TraceReportRepository $figures,
        private readonly TraceRepository $traces,
        /** tasks a run gave to neighbours and what they spent; null — no network of agents */
        private readonly ?\OpenCCK\Domain\Repository\PeerRepository $peers = null,
        /** @var (\Closure(int): bool)|null runs whose trace is not shown: those of former owners */
        private readonly ?\Closure $hiddenRun = null,
    ) {}

    public function usage(int $days): string {
        $days = max(1, min(90, $days));
        $since = new DateTimeImmutable(sprintf('-%d days', $days));
        $lines = [sprintf(t('service.trace_reports.rashod_za_dn'), $days), '', t('service.trace_reports.po_modelyam')];
        $total = 0.0;
        foreach ($this->figures->usageByModel($since) as $row) {
            $total += $row['cost'];
            $lines[] = sprintf(t('service.trace_reports.vyzovov_tokenov'), $row['model'], self::usd($row['cost']), $row['calls'], self::n($row['input']), self::n($row['output']));
        }
        $lines[] = '';
        $lines[] = t('service.trace_reports.po_dnyam');
        foreach ($this->figures->usageByDay($since) as $row) {
            $lines[] = sprintf(t('service.trace_reports.vyzovov'), $row['day'], self::usd($row['cost']), $row['calls']);
        }
        $costliest = $this->figures->costliestRuns($since, 5);
        if ($costliest !== []) {
            $lines[] = '';
            $lines[] = t('service.trace_reports.samye_dorogie_zapuski');
            foreach ($costliest as $row) {
                $lines[] = sprintf(t('service.trace_reports.tokenov'), $row['id'], self::usd($row['cost']), self::n($row['tokens']));
            }
        }
        $lines[] = '';
        $lines[] = t('service.trace_reports.itogo') . self::usd($total);
        return implode("\n", $lines);
    }

    /**
     * Spend split by topic, chat, project, participant, model or day for a period, with the total.
     *
     * spec: BRIEF.md#трассировка-и-метрики — spend by topics, projects, participants and periods
     */
    public function breakdown(\OpenCCK\Domain\Agent\SpendQuery $query, string $scope): string {
        $rows = $this->figures->breakdown($query);
        $last = $query->to->modify('-1 second');
        $lines = [sprintf(t('service.trace_reports.breakdown_head'), t('service.trace_reports.by_' . $query->by), $query->from->format('Y-m-d'), $last->format('Y-m-d'), $query->from->getTimezone()->getName()) . ($scope === '' ? '' : ' — ' . $scope), ''];
        $total = 0.0;
        $calls = 0;
        foreach ($rows as $row) {
            $total += $row['cost'];
            $calls += $row['calls'];
            $lines[] = sprintf(t('service.trace_reports.breakdown_row'), self::label($query->by, $row), self::usd($row['cost']), $row['calls'], self::n($row['input']), self::n($row['output']));
        }
        if ($rows === []) {
            $lines[] = t('service.trace_reports.breakdown_none');
        }
        $lines[] = '';
        $lines[] = sprintf(t('service.trace_reports.breakdown_total'), self::usd($total), $calls);
        return implode("\n", $lines);
    }

    /** @param array{chat: ?string, chatId: ?string, thread: string, project: ?string, person: ?string, label: string, calls: int, input: int, output: int, cost: float} $row */
    private static function label(string $by, array $row): string {
        $chat = match (true) {
            $row['chatId'] === null => t('service.trace_reports.service_calls'),
            $row['chat'] !== null => $row['chat'],
            str_starts_with($row['chatId'], '-') => sprintf(t('service.trace_reports.group'), $row['chatId']),
            default => sprintf(t('service.trace_reports.private_chat'), $row['person'] ?? $row['chatId']),
        };
        $thread = $row['thread'];
        $topic = $row['project'] ?? ($thread === '' ? t('service.trace_reports.general_topic') : sprintf(t('service.trace_reports.topic'), $thread));
        $shared = $row['chatId'] !== null && ($thread !== '' || str_starts_with($row['chatId'], '-'));
        return match ($by) {
            'topic' => $shared ? $chat . ' / ' . $topic : $chat,
            'chat' => $chat,
            'project' => $row['project'] ?? t('service.trace_reports.no_project'),
            'participant' => $row['person'] ?? t('service.trace_reports.service_calls'),
            default => $row['label'],
        };
    }

    public function runs(int $limit = 10): string {
        $rows = $this->figures->recentRuns($limit);
        if ($rows === []) {
            return t('service.trace_reports.zapuskov_esche_ne_bylo');
        }
        $lines = [t('service.trace_reports.poslednie_zapuski')];
        foreach ($rows as $row) {
            $lines[] = sprintf(
                t('service.trace_reports.hodov_tokenov'),
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
        $lines[] = t('service.trace_reports.razbor_zapuska_trace_nomer');
        return implode("\n", $lines);
    }

    /**
     * Whether delegation pays: how often the agent split tasks, how workers ended by model, what they cost.
     *
     * spec: BRIEF.md#часть-4--проверяющий-и-данные-трасс — /delegation
     */
    public function delegation(int $days): string {
        $days = max(1, min(90, $days));
        $since = new DateTimeImmutable(sprintf('-%d days', $days));
        $f = $this->figures->delegation($since);
        $lines = [
            sprintf(t('service.trace_reports.delegirovanie_za_dn'), $days),
            '',
            sprintf(t('service.trace_reports.zapuskov_iz_nih_s_delegirovaniem'), $f['top'], $f['delegating'], self::share($f['delegating'], $f['top'])),
            sprintf(t('service.trace_reports.vorkerov_povtorov_kaskada_fonovyh_delegi'), $f['workers'], $f['cascades'], $f['background']),
            sprintf(t('service.trace_reports.proveryayuschih_otklonili_otvet'), $f['verifiers'], $f['rejected']),
        ];
        $models = $this->figures->workerModels($since);
        if ($models !== []) {
            $lines[] = '';
            $lines[] = t('service.trace_reports.zapuski_vorkerov_po_modelyam');
            foreach ($models as $row) {
                $lines[] = sprintf(
                    t('service.trace_reports.otvetili_otkazy_modeli_tokenov_v'),
                    $row['model'],
                    $row['runs'],
                    self::share($row['answered'], $row['runs']),
                    $row['failed'],
                    self::n($row['tokens']),
                    self::usd($row['cost']),
                    self::seconds($row['active_ms']),
                );
            }
        }
        return implode("\n", $lines);
    }

    /** The steps of a run in order; null when the run has no trace. */
    public function trace(int $runId): ?string {
        // spec: BRIEF.md#несколько-пользователей — the private chats of a former owner stay theirs
        if ($this->hiddenRun !== null && ($this->hiddenRun)($runId)) {
            return null;
        }
        $spans = $this->traces->spans($runId);
        if ($spans === []) {
            return null;
        }
        $lines = [];
        $cost = 0.0;
        foreach ($spans as $span) {
            $cost += $span->costUsd ?? 0.0;
            if ($span->kind === TraceSpan::RUN) {
                array_unshift($lines, sprintf(t('service.trace_reports.zapusk'), $runId, $span->name, $span->status, $span->durationMs === null ? t('service.trace_reports.idet') : self::seconds($span->durationMs)), '');
                continue;
            }
            $line = sprintf('%s %s — %s, %s', $span->kind, $span->name, $span->status, self::seconds($span->durationMs ?? 0));
            if ($span->inputTokens > 0 || $span->outputTokens > 0) {
                $line .= sprintf(t('service.trace_reports.vhod_kesh_vyhod'), $span->inputTokens, $span->cacheReadTokens, $span->outputTokens);
            }
            if ($span->costUsd !== null) {
                $line .= ', ' . self::usd($span->costUsd) . ($span->costStatus === TraceSpan::COST_ESTIMATED ? t('service.trace_reports.otsenka') : '');
            }
            if ($span->error !== null) {
                $line .= ' — ' . $span->error;
            }
            $lines[] = $line;
        }
        // spec: BRIEF.md#сеть-агентов — the spend of the neighbours in the same unit as the own steps
        foreach ($this->peers?->outgoingOfRun($runId) ?? [] as $remote) {
            $cost += $remote['usd'] ?? 0.0;
            $lines[] = sprintf(t('service.trace_reports.remote'), $remote['peer'], $remote['remote'], $remote['run'] === null ? '?' : '#' . $remote['run'], $remote['state'], $remote['tokens'], $remote['usd'] === null ? '?' : self::usd($remote['usd']));
        }
        $lines[] = '';
        $lines[] = t('service.trace_reports.stoimost_shagov') . self::usd($cost);
        return implode("\n", $lines);
    }

    private static function share(int $part, int $whole): string {
        return $whole === 0 ? '—' : sprintf('%d%%', (int) round(100 * $part / $whole));
    }

    private static function usd(float $cost): string {
        return sprintf('$%.4f', $cost);
    }

    private static function n(int $value): string {
        return number_format($value, 0, '.', ' ');
    }

    private static function seconds(int $ms): string {
        return $ms < 10_000 ? sprintf(t('service.trace_reports.s'), $ms / 1000) : sprintf(t('service.trace_reports.s_2'), intdiv($ms, 1000));
    }
}
