<?php

declare(strict_types=1);

namespace OpenCCK\App\Improve;

use OpenCCK\App\Eval\EvalReport;
use OpenCCK\App\Eval\EvalRunner;
use OpenCCK\App\Eval\EvalTask;

use function Amp\async;

/**
 * The gate of a prompt change: the evaluation set runs the current notes and the candidate under the same model, the
 * same judge and the same number of runs, and the candidate passes only when no category got worse beyond the noise.
 *
 * spec: brief/decisions.md#самоулучшение-решено-2026-11-18 — at least 20 tasks by categories, at least 3 runs, a judge of
 *       another family, the prompt version recorded; no category worse beyond the noise
 * ref: notes/claude-cookbooks.md — scores by category, so a regression shows where it happened
 */
final class PromptGate {
    /** @param list<EvalTask> $tasks */
    public function __construct(
        private readonly EvalRunner $runner,
        private readonly array $tasks,
        private readonly string $model,
        /** runs of every task and variant; fewer than 3 is not a check */
        private readonly int $runs,
        private readonly bool $judged,
        /** tasks running at once */
        private readonly int $parallel = 4,
    ) {}

    private ?\OpenCCK\Domain\Repository\EvalScoreRepository $scores = null;

    /** spec: brief/decisions.md#самоулучшение-решено-2026-11-18 — the scores of both versions are kept by category */
    public function useScores(\OpenCCK\Domain\Repository\EvalScoreRepository $scores): void {
        $this->scores = $scores;
    }

    public function __invoke(string $current, string $candidate): GateVerdict {
        if (!$this->judged) {
            return new GateVerdict(false, \Mercurius\I18n\t('improve.gate.no_judge'));
        }
        if (count($this->tasks) < 20 || $this->runs < 3) {
            return new GateVerdict(false, \Mercurius\I18n\t('improve.gate.too_small'));
        }
        $base = EvalReport::categories($this->run($current));
        $now = EvalReport::categories($this->run($candidate));
        $this->scores?->record(PromptNotes::versionKey($current), $this->model, $base);
        $this->scores?->record(PromptNotes::versionKey($candidate), $this->model, $now);
        $regressions = EvalReport::regressions($now, $base);
        $lines = [];
        foreach ($now as $category => $stats) {
            $lines[] = sprintf('%s: %.2f → %.2f', $category, $base[$category]['mean'] ?? 0.0, $stats['mean']);
        }
        $report = sprintf(\Mercurius\I18n\t('improve.gate.report'), count($this->tasks), $this->runs, $this->model, self::overall($base), self::overall($now))
            . "\n" . implode("\n", $lines)
            . ($regressions === [] ? '' : "\n\n" . \Mercurius\I18n\t('improve.gate.worse') . "\n" . implode("\n", $regressions));
        return new GateVerdict($regressions === [], $report, ['base' => $base, 'candidate' => $now]);
    }

    /** @return list<array{task: string, category: string, score: float, checks: ?float, judge: ?float, tokens: int, calls: list<string>, answer: string, failures: list<string>, reason: string}> */
    private function run(string $notes): array {
        $jobs = [];
        foreach ($this->tasks as $task) {
            for ($i = 0; $i < $this->runs; $i++) {
                $jobs[] = $task;
            }
        }
        $lanes = array_fill(0, max(1, $this->parallel), []);
        foreach ($jobs as $i => $task) {
            $lanes[$i % count($lanes)][] = $task;
        }
        $futures = [];
        foreach ($lanes as $lane) {
            $futures[] = async(function () use ($lane, $notes): array {
                $results = [];
                foreach ($lane as $task) {
                    $results[] = $this->runner->runTask($task, $this->model, $notes);
                }
                return $results;
            });
        }
        $all = [];
        foreach ($futures as $future) {
            /** @var list<array{task: string, category: string, score: float, checks: ?float, judge: ?float, tokens: int, calls: list<string>, answer: string, failures: list<string>, reason: string}> $chunk */
            $chunk = $future->await();
            foreach ($chunk as $result) {
                $all[] = $result;
            }
        }
        return $all;
    }

    /** @param array<string, array{mean: float, se: float, n: int}> $categories */
    private static function overall(array $categories): float {
        if ($categories === []) {
            return 0.0;
        }
        $sum = 0.0;
        foreach ($categories as $stats) {
            $sum += $stats['mean'];
        }
        return $sum / (float) count($categories);
    }
}
