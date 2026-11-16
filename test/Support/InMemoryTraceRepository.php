<?php

declare(strict_types=1);

namespace OpenCCK\Test\Support;

use DateTimeImmutable;
use OpenCCK\Domain\Agent\TraceSpan;
use OpenCCK\Domain\Repository\TraceRepository;
use RuntimeException;

final class InMemoryTraceRepository implements TraceRepository {
    /** @var array<int, TraceSpan> */
    public array $spans = [];
    /** @var array<int, array{request: array<array-key, mixed>, response: array<array-key, mixed>, at: DateTimeImmutable}> */
    public array $payloads = [];
    /** set to make every write fail, as a database that is down */
    public bool $failing = false;
    private int $next = 1;

    public function openRun(int $runId, string $name): int {
        $this->check();
        foreach ($this->spans as $span) {
            if ($span->runId === $runId && $span->kind === TraceSpan::RUN) {
                return (int) $span->id;
            }
        }
        return $this->record(new TraceSpan($runId, null, TraceSpan::RUN, $name, new DateTimeImmutable(), null, TraceSpan::RUNNING));
    }

    public function closeRun(int $runId, string $status, int $durationMs, ?string $error): void {
        $this->check();
        foreach ($this->spans as $id => $s) {
            if ($s->runId === $runId && $s->kind === TraceSpan::RUN) {
                $this->spans[$id] = new TraceSpan($s->runId, null, $s->kind, $s->name, $s->startedAt, $durationMs, $status, error: $error, id: $id);
            }
        }
    }

    public function record(TraceSpan $span): int {
        $this->check();
        $id = $this->next++;
        $this->spans[$id] = new TraceSpan(
            $span->runId, $span->parentId, $span->kind, $span->name, $span->startedAt, $span->durationMs, $span->status,
            $span->inputTokens, $span->outputTokens, $span->cacheReadTokens, $span->cacheWriteTokens, $span->reasoningTokens,
            $span->costUsd, $span->costStatus, $span->error, $span->attrs, $id,
        );
        return $id;
    }

    public function payload(int $spanId, array $request, array $response): void {
        $this->check();
        $this->payloads[$spanId] = ['request' => $request, 'response' => $response, 'at' => new DateTimeImmutable()];
    }

    public function payloadOf(int $spanId): ?array {
        $p = $this->payloads[$spanId] ?? null;
        return $p === null ? null : ['request' => $p['request'], 'response' => $p['response']];
    }

    public function purgePayloads(DateTimeImmutable $before): int {
        $count = 0;
        foreach ($this->payloads as $id => $p) {
            if ($p['at'] < $before) {
                unset($this->payloads[$id]);
                $count++;
            }
        }
        return $count;
    }

    public function spans(int $runId): array {
        return array_values(array_filter($this->spans, static fn(TraceSpan $s): bool => $s->runId === $runId));
    }

    private function check(): void {
        if ($this->failing) {
            throw new RuntimeException('trace store is down');
        }
    }
}
