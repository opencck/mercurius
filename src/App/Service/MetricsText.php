<?php

declare(strict_types=1);

namespace OpenCCK\App\Service;

use OpenCCK\Domain\Repository\MetricsRepository;

/**
 * The Prometheus text exposition format (0.0.4) of the agent's counters.
 *
 * spec: BRIEF.md#трассировка-и-метрики — runs, tokens and cost by model, tools, step durations, approvals
 * ref: .claude/skills/skill-opencck/references/prometheus-exporter.md — HELP and TYPE lines, labels in braces; here
 *      label values are escaped as the format requires
 */
final class MetricsText {
    /** Seconds: from a quick tool to a long model answer. */
    public const BOUNDS = [0.1, 0.5, 1.0, 2.5, 5.0, 10.0, 30.0, 60.0, 300.0];
    private const BUCKETS = ['input', 'output', 'cache_read', 'cache_write', 'reasoning'];
    private const ACTIVE = ['running', 'waiting_approval'];

    public function __construct(private readonly MetricsRepository $figures) {}

    public function render(): string {
        $out = [];
        $runs = $this->figures->runs();
        self::head($out, 'mercurius_runs_total', 'counter', 'Finished runs by final status and stop reason');
        foreach ($runs as $row) {
            if (!in_array($row['status'], self::ACTIVE, true)) {
                $out[] = self::line('mercurius_runs_total', ['status' => $row['status'], 'reason' => $row['reason']], $row['count']);
            }
        }
        self::head($out, 'mercurius_runs_active', 'gauge', 'Runs going on now, by status');
        foreach ($runs as $row) {
            if (in_array($row['status'], self::ACTIVE, true)) {
                $out[] = self::line('mercurius_runs_active', ['status' => $row['status']], $row['count']);
            }
        }
        $models = $this->figures->models();
        self::head($out, 'mercurius_model_tokens_total', 'counter', 'Tokens of model calls by bucket');
        foreach ($models as $row) {
            foreach (self::BUCKETS as $bucket) {
                $out[] = self::line('mercurius_model_tokens_total', ['model' => $row['model'], 'bucket' => $bucket], $row[$bucket]);
            }
        }
        self::head($out, 'mercurius_model_cost_usd_total', 'counter', 'Cost of model calls in USD');
        foreach ($models as $row) {
            $out[] = self::line('mercurius_model_cost_usd_total', ['model' => $row['model']], $row['cost']);
        }
        self::head($out, 'mercurius_tool_calls_total', 'counter', 'Tool calls by tool and status');
        foreach ($this->figures->tools() as $row) {
            $out[] = self::line('mercurius_tool_calls_total', ['tool' => $row['tool'], 'status' => $row['status']], $row['count']);
        }
        self::head($out, 'mercurius_approvals_total', 'counter', 'Approvals by outcome');
        foreach ($this->figures->approvals() as $row) {
            $out[] = self::line('mercurius_approvals_total', ['outcome' => $row['outcome']], $row['count']);
        }
        self::head($out, 'mercurius_workers_total', 'counter', 'Worker runs by kind (worker, retry, verifier) and status');
        foreach ($this->figures->workers() as $row) {
            $out[] = self::line('mercurius_workers_total', ['kind' => $row['kind'], 'status' => $row['status']], $row['count']);
        }
        self::head($out, 'mercurius_step_duration_seconds', 'histogram', 'Duration of run steps by kind');
        foreach ($this->figures->durations(self::BOUNDS) as $row) {
            foreach (self::BOUNDS as $i => $bound) {
                $out[] = self::line('mercurius_step_duration_seconds_bucket', ['kind' => $row['kind'], 'le' => self::number($bound)], $row['buckets'][$i] ?? 0);
            }
            $out[] = self::line('mercurius_step_duration_seconds_bucket', ['kind' => $row['kind'], 'le' => '+Inf'], $row['count']);
            $out[] = self::line('mercurius_step_duration_seconds_sum', ['kind' => $row['kind']], $row['sum']);
            $out[] = self::line('mercurius_step_duration_seconds_count', ['kind' => $row['kind']], $row['count']);
        }
        return implode("\n", $out) . "\n";
    }

    /** A label value as the format wants it: backslash, quote and newline escaped. */
    public static function label(string $value): string {
        return str_replace(['\\', '"', "\n"], ['\\\\', '\\"', '\\n'], $value);
    }

    /** @param list<string> $out */
    private static function head(array &$out, string $name, string $type, string $help): void {
        $out[] = sprintf('# HELP %s %s', $name, $help);
        $out[] = sprintf('# TYPE %s %s', $name, $type);
    }

    /** @param array<string, string> $labels */
    private static function line(string $name, array $labels, int|float $value): string {
        $pairs = [];
        foreach ($labels as $key => $label) {
            $pairs[] = sprintf('%s="%s"', $key, self::label($label));
        }
        return sprintf('%s{%s} %s', $name, implode(',', $pairs), self::number($value));
    }

    private static function number(int|float $value): string {
        if (is_int($value)) {
            return (string) $value;
        }
        $text = rtrim(rtrim(sprintf('%.8F', $value), '0'), '.');
        return $text === '' || $text === '-0' ? '0' : $text;
    }
}
