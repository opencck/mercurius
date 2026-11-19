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
        // spec: BRIEF.md#трассировка-и-метрики — which tools are used, which fail, which are slow
        $tools = $this->figures->toolsUsage($since);
        if ($tools !== []) {
            $lines[] = '';
            $lines[] = t('service.trace_reports.tools_head');
            foreach ($tools as $row) {
                $lines[] = sprintf(t('service.trace_reports.tool_row'), $row['name'], $row['calls'], $row['errors'], self::share($row['errors'], $row['calls']), self::seconds($row['avg_ms']));
            }
        }
        $longest = $this->figures->longestSteps($since, 5);
        if ($longest !== []) {
            $lines[] = '';
            $lines[] = t('service.trace_reports.longest_head');
            foreach ($longest as $row) {
                $lines[] = sprintf(t('service.trace_reports.longest_row'), $row['kind'], $row['name'], self::seconds($row['ms']), $row['run']);
            }
        }
        // spec: BRIEF.md#трассировка-и-метрики — did a new prompt make the agent dearer
        $versions = $this->figures->usageByPromptVersion($since);
        if ($versions !== []) {
            $lines[] = '';
            $lines[] = t('service.trace_reports.by_prompt_version');
            foreach ($versions as $row) {
                $lines[] = sprintf(t('service.trace_reports.usage_version_row'), self::version($row['version']), self::usd($row['cost']), $row['runs'], $row['calls']);
            }
        }
        $lines[] = '';
        $lines[] = t('service.trace_reports.itogo') . self::usd($total);
        return implode("\n", $lines);
    }

    private static function version(?int $version): string {
        return $version === null ? t('service.trace_reports.no_version') : 'v' . $version;
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

    public const RUN_STATUSES = ['running', 'waiting_approval', 'done', 'failed', 'stopped'];

    /**
     * The latest top-level runs, of a status if one is named.
     *
     * spec: BRIEF.md#трассировка-и-метрики — /runs <count> <status>
     */
    public function runs(int $limit = 10, ?string $status = null): string {
        $rows = $this->figures->recentRuns(max(1, min(50, $limit)), $status !== null && in_array($status, self::RUN_STATUSES, true) ? $status : null);
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
        // spec: BRIEF.md#трассировка-и-метрики — how much of a delegating run the orchestrator itself spends
        $tokens = $this->figures->delegationTokens($since);
        if ($tokens['orchestrator'] + $tokens['workers'] > 0) {
            $lines[] = sprintf(t('service.trace_reports.orchestrator_share'), self::share($tokens['orchestrator'], $tokens['orchestrator'] + $tokens['workers']), self::n($tokens['orchestrator']), self::n($tokens['workers']));
        }
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
        // spec: BRIEF.md#трассировка-и-метрики — did a new prompt change how the agent delegates
        $versions = $this->figures->delegationByPromptVersion($since);
        if ($versions !== []) {
            $lines[] = '';
            $lines[] = t('service.trace_reports.by_prompt_version');
            foreach ($versions as $row) {
                $lines[] = sprintf(t('service.trace_reports.delegation_version_row'), self::version($row['version']), $row['top'], $row['delegating'], self::share($row['delegating'], $row['top']), $row['workers'], self::usd($row['cost']));
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
        $number = 0;
        foreach ($spans as $span) {
            $cost += $span->costUsd ?? 0.0;
            if ($span->kind !== TraceSpan::RUN) {
                // spec: BRIEF.md#трассировка-и-метрики — numbered, so /trace <run> <step> can name one
                $number++;
            }
            // spec: BRIEF.md#трассировка-и-метрики — nested traces: a worker's steps under its step, its spend in the sum
            if ($span->kind === TraceSpan::WORKER) {
                $lines[] = $number . '. ' . sprintf('%s %s — %s, %s', $span->kind, $span->name, $span->status, self::seconds($span->durationMs ?? 0))
                    . (isset($span->attrs['model']) && is_string($span->attrs['model']) ? ', ' . $span->attrs['model'] : '');
                $child = isset($span->attrs['run']) && is_int($span->attrs['run']) ? $span->attrs['run'] : null;
                foreach ($child === null ? [] : $this->traces->spans($child) as $step) {
                    if ($step->kind === TraceSpan::RUN) {
                        continue;
                    }
                    $cost += $step->costUsd ?? 0.0;
                    $lines[] = '    ' . self::step($step);
                }
                continue;
            }
            if ($span->kind === TraceSpan::RUN) {
                // spec: BRIEF.md#выбор-моделей-под-задачу — why this model: every decision taken for the run
                $why = array_map(static fn(array $d): string => sprintf(t('service.trace_reports.model_chosen'), $d['model'], $d['method'], $d['reason']), $this->figures->decisions($runId));
                array_unshift($lines, sprintf(t('service.trace_reports.zapusk'), $runId, $span->name, $span->status, $span->durationMs === null ? t('service.trace_reports.idet') : self::seconds($span->durationMs)), ...$why, ...['']);
                continue;
            }
            $lines[] = $number . '. ' . self::step($span);
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

    /**
     * The full prompt and answer of a numbered step of a run, as JSON; null — there is no such step. A step without
     * them (a tool, or a payload past its keeping time) says so.
     *
     * spec: BRIEF.md#трассировка-и-метрики — /trace <run> <step>
     *
     * @return array{json: ?string, note: ?string}|null
     */
    public function payload(int $runId, int $step): ?array {
        if ($this->hiddenRun !== null && ($this->hiddenRun)($runId)) {
            return null;
        }
        $steps = array_values(array_filter($this->traces->spans($runId), static fn(TraceSpan $s): bool => $s->kind !== TraceSpan::RUN));
        $span = $steps[$step - 1] ?? null;
        if ($span === null) {
            return null;
        }
        $payload = $span->id === null ? null : $this->traces->payloadOf($span->id);
        if ($payload === null) {
            return ['json' => null, 'note' => t('service.trace_reports.no_payload')];
        }
        $json = json_encode(['step' => $step, 'kind' => $span->kind, 'name' => $span->name, ...$payload], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        return $json === false ? ['json' => null, 'note' => t('service.trace_reports.no_payload')] : ['json' => $json, 'note' => null];
    }

    /** One step: kind, name, status, time, tokens, cost and error. */
    private static function step(TraceSpan $span): string {
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
        return $line;
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
