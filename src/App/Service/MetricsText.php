<?php

declare(strict_types=1);

namespace OpenCCK\App\Service;

use OpenCCK\Domain\Repository\MetricsRepository;

/**
 * The Prometheus text exposition format (0.0.4) of the agent's counters.
 *
 * spec: brief/operations.md#метрики-prometheus — runs, tokens and cost by model, tools, step durations, approvals
 * ref: .claude/skills/skill-opencck/references/prometheus-exporter.md — HELP and TYPE lines, labels in braces; here
 *      label values are escaped as the format requires
 */
final class MetricsText {
    /** Seconds: from a quick tool to a long model answer. */
    public const BOUNDS = [0.1, 0.5, 1.0, 2.5, 5.0, 10.0, 30.0, 60.0, 300.0];
    private const BUCKETS = ['input', 'output', 'cache_read', 'cache_write', 'reasoning'];
    private const ACTIVE = ['running', 'waiting_approval'];

    /** workers a run may start: none, one, the parallel three, a full delegation and its retries */
    private const WORKER_BOUNDS = [0, 1, 2, 3, 5, 10];

    public function __construct(
        private readonly MetricsRepository $figures,
        /** @var (\Closure(): array<string, bool>)|null the modules set up and whether each answers now */
        private readonly ?\Closure $modules = null,
        /** @var (\Closure(): list<\OpenCCK\Domain\Mcp\McpServer>)|null the MCP connections of every context */
        private readonly ?\Closure $mcp = null,
    ) {}

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
        // spec: brief/operations.md#метрики-prometheus — how many workers a run starts
        $workers = $this->figures->workersPerRun(self::WORKER_BOUNDS);
        self::head($out, 'mercurius_workers_per_run', 'histogram', 'Worker runs started by each finished top-level run');
        foreach (self::WORKER_BOUNDS as $i => $bound) {
            $out[] = self::line('mercurius_workers_per_run_bucket', ['le' => (string) $bound], $workers['buckets'][$i] ?? 0);
        }
        $out[] = self::line('mercurius_workers_per_run_bucket', ['le' => '+Inf'], $workers['count']);
        $out[] = self::line('mercurius_workers_per_run_sum', [], $workers['sum']);
        $out[] = self::line('mercurius_workers_per_run_count', [], $workers['count']);
        // spec: brief/channels.md#здоровье-локального-сервера-bot-api — whether each module answers, the local Bot API among them
        $modules = $this->modules === null ? [] : ($this->modules)();
        if ($modules !== []) {
            self::head($out, 'mercurius_module_up', 'gauge', 'Whether a module service answers its check now');
            foreach ($modules as $name => $up) {
                $out[] = self::line('mercurius_module_up', ['module' => $name], $up ? 1 : 0);
            }
        }
        // spec: brief/skills-mcp.md#три-слоя-установленный-mcp-запись-подключение — the state of each connection
        $connections = $this->mcp === null ? [] : ($this->mcp)();
        if ($connections !== []) {
            self::head($out, 'mercurius_mcp_connection', 'gauge', 'The state of each MCP connection: 1 for its state now');
            foreach ($connections as $server) {
                $out[] = self::line('mercurius_mcp_connection', ['context' => $server->context, 'name' => $server->name, 'state' => $server->status], 1);
            }
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
        return $pairs === [] ? sprintf('%s %s', $name, self::number($value)) : sprintf('%s{%s} %s', $name, implode(',', $pairs), self::number($value));
    }

    private static function number(int|float $value): string {
        if (is_int($value)) {
            return (string) $value;
        }
        $text = rtrim(rtrim(sprintf('%.8F', $value), '0'), '.');
        return $text === '' || $text === '-0' ? '0' : $text;
    }
}
