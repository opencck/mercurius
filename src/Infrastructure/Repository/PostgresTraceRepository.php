<?php

declare(strict_types=1);

namespace OpenCCK\Infrastructure\Repository;

use Amp\Postgres\PostgresLink;
use DateTimeImmutable;
use OpenCCK\Domain\Agent\TraceSpan;
use OpenCCK\Domain\Repository\TraceRepository;
use UnexpectedValueException;

final class PostgresTraceRepository implements TraceRepository {
    private const COLUMNS = "id, run_id, parent_id, kind, name, extract(epoch FROM started_at)::float8 AS started, duration_ms, status,
        input_tokens, output_tokens, cache_read_tokens, cache_write_tokens, reasoning_tokens, cost_usd::float8 AS cost_usd, cost_status, error,
        attrs::text AS attrs";

    public function __construct(private readonly PostgresLink $db) {}

    public function openRun(int $runId, string $name): int {
        $row = $this->db->execute(
            "INSERT INTO trace_spans (run_id, kind, name, started_at, status) VALUES (?, 'run', ?, now(), 'running')
             ON CONFLICT (run_id) WHERE kind = 'run' DO UPDATE SET name = trace_spans.name
             RETURNING id",
            [$runId, mb_substr($name, 0, 200)],
        )->fetchRow();
        return self::int($row['id'] ?? null);
    }

    public function closeRun(int $runId, string $status, int $durationMs, ?string $error): void {
        $this->db->execute(
            "UPDATE trace_spans SET status = ?, duration_ms = ?, error = ? WHERE run_id = ? AND kind = 'run'",
            [$status, $durationMs, $error, $runId],
        );
    }

    public function record(TraceSpan $span): int {
        $row = $this->db->execute(
            'INSERT INTO trace_spans (run_id, parent_id, kind, name, started_at, duration_ms, status, input_tokens, output_tokens,
                cache_read_tokens, cache_write_tokens, reasoning_tokens, cost_usd, cost_status, error, attrs)
             VALUES (?, ?, ?, ?, to_timestamp(?), ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?::jsonb) RETURNING id',
            [
                $span->runId, $span->parentId, $span->kind, mb_substr($span->name, 0, 200),
                (float) $span->startedAt->format('U.u'), $span->durationMs, $span->status,
                $span->inputTokens, $span->outputTokens, $span->cacheReadTokens, $span->cacheWriteTokens, $span->reasoningTokens,
                $span->costUsd, $span->costStatus, $span->error === null ? null : mb_substr($span->error, 0, 1000),
                json_encode((object) $span->attrs, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            ],
        )->fetchRow();
        return self::int($row['id'] ?? null);
    }

    public function payload(int $spanId, array $request, array $response): void {
        $flags = JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE;
        $this->db->execute(
            'INSERT INTO trace_payloads (span_id, request, response) VALUES (?, ?::jsonb, ?::jsonb)
             ON CONFLICT (span_id) DO UPDATE SET request = EXCLUDED.request, response = EXCLUDED.response',
            [$spanId, json_encode($request, $flags), json_encode($response, $flags)],
        );
    }

    public function payloadOf(int $spanId): ?array {
        $row = $this->db->execute('SELECT request::text AS request, response::text AS response FROM trace_payloads WHERE span_id = ?', [$spanId])->fetchRow();
        if ($row === null) {
            return null;
        }
        return ['request' => self::json($row['request'] ?? null), 'response' => self::json($row['response'] ?? null)];
    }

    public function purgePayloads(DateTimeImmutable $before): int {
        $row = $this->db->execute(
            'WITH gone AS (DELETE FROM trace_payloads WHERE created_at < to_timestamp(?) RETURNING 1) SELECT count(*)::int AS n FROM gone',
            [$before->getTimestamp()],
        )->fetchRow();
        return self::int($row['n'] ?? 0);
    }

    public function spans(int $runId): array {
        $spans = [];
        foreach ($this->db->execute('SELECT ' . self::COLUMNS . ' FROM trace_spans WHERE run_id = ? ORDER BY id', [$runId]) as $row) {
            $spans[] = self::span($row);
        }
        return $spans;
    }

    /** @param array<string, mixed> $row */
    private static function span(array $row): TraceSpan {
        $kind = $row['kind'] ?? null;
        $name = $row['name'] ?? null;
        $status = $row['status'] ?? null;
        $costStatus = $row['cost_status'] ?? null;
        $started = $row['started'] ?? null;
        if (!is_string($kind) || !is_string($name) || !is_string($status) || !is_string($costStatus) || !is_float($started)) {
            throw new UnexpectedValueException('Malformed trace_spans row');
        }
        $attrs = [];
        $decoded = self::json($row['attrs'] ?? null);
        foreach (array_keys($decoded) as $key) {
            /** @var mixed $value */
            $value = $decoded[$key];
            if (is_string($key) && (is_scalar($value) || $value === null)) {
                $attrs[$key] = $value;
            }
        }
        return new TraceSpan(
            self::int($row['run_id'] ?? null),
            self::nullableInt($row, 'parent_id'),
            $kind,
            $name,
            DateTimeImmutable::createFromFormat('U.u', sprintf('%.6F', $started)) ?: new DateTimeImmutable(),
            self::nullableInt($row, 'duration_ms'),
            $status,
            self::int($row['input_tokens'] ?? 0),
            self::int($row['output_tokens'] ?? 0),
            self::int($row['cache_read_tokens'] ?? 0),
            self::int($row['cache_write_tokens'] ?? 0),
            self::int($row['reasoning_tokens'] ?? 0),
            isset($row['cost_usd']) && is_float($row['cost_usd']) ? $row['cost_usd'] : null,
            $costStatus,
            isset($row['error']) && is_string($row['error']) ? $row['error'] : null,
            $attrs,
            self::int($row['id'] ?? null),
        );
    }

    /** @param array<string, mixed> $row */
    private static function nullableInt(array $row, string $key): ?int {
        return isset($row[$key]) && is_int($row[$key]) ? $row[$key] : null;
    }

    private static function int(mixed $value): int {
        if (!is_int($value)) {
            throw new UnexpectedValueException('Malformed trace row: expected an integer');
        }
        return $value;
    }

    /** @return array<array-key, mixed> */
    private static function json(mixed $text): array {
        if (!is_string($text)) {
            return [];
        }
        /** @var mixed $value */
        $value = json_decode($text, true, 64, JSON_THROW_ON_ERROR);
        return is_array($value) ? $value : [];
    }
}
