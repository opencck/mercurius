<?php

declare(strict_types=1);

namespace OpenCCK\Test\Support;

use Amp\PHPUnit\AsyncTestCase;
use DateTimeImmutable;
use OpenCCK\Domain\Agent\TraceSpan;
use OpenCCK\Domain\Repository\TraceRepository;

/**
 * Behaviour of the trace store, run on the in-memory fake locally and on PostgreSQL on the server.
 */
abstract class TraceStoreContract extends AsyncTestCase {
    protected TraceRepository $traces;
    protected int $runId;

    private function step(string $kind, string $name, ?int $parent, int $ms = 10): TraceSpan {
        return new TraceSpan(
            runId: $this->runId,
            parentId: $parent,
            kind: $kind,
            name: $name,
            startedAt: new DateTimeImmutable('2026-11-16 12:00:00'),
            durationMs: $ms,
            status: TraceSpan::OK,
            inputTokens: 100,
            outputTokens: 20,
            cacheReadTokens: 30,
            costUsd: 0.0012,
            costStatus: TraceSpan::COST_ACTUAL,
            attrs: ['model' => 'openrouter:x/y'],
        );
    }

    public function testRunSpanIsOpenedOnceAndClosed(): void {
        $first = $this->traces->openRun($this->runId, 'задача');
        $again = $this->traces->openRun($this->runId, 'задача после перезапуска');

        self::assertSame($first, $again);
        $this->traces->closeRun($this->runId, TraceSpan::OK, 1500, null);

        $spans = $this->traces->spans($this->runId);
        self::assertCount(1, $spans);
        self::assertSame(TraceSpan::RUN, $spans[0]->kind);
        self::assertSame(1500, $spans[0]->durationMs);
        self::assertSame(TraceSpan::OK, $spans[0]->status);
    }

    public function testStepsComeBackInOrderWithTheirNumbers(): void {
        $run = $this->traces->openRun($this->runId, 'задача');
        $model = $this->traces->record($this->step(TraceSpan::MODEL, 'openrouter:x/y', $run, 900));
        $this->traces->record($this->step(TraceSpan::TOOL, 'read_file', $run, 40));

        $spans = $this->traces->spans($this->runId);

        self::assertSame([TraceSpan::RUN, TraceSpan::MODEL, TraceSpan::TOOL], array_map(static fn(TraceSpan $s): string => $s->kind, $spans));
        self::assertSame($model, $spans[1]->id);
        self::assertSame($run, $spans[1]->parentId);
        self::assertSame(100, $spans[1]->inputTokens);
        self::assertSame(30, $spans[1]->cacheReadTokens);
        self::assertEqualsWithDelta(0.0012, $spans[1]->costUsd, 0.0000001);
        self::assertSame(TraceSpan::COST_ACTUAL, $spans[1]->costStatus);
        self::assertSame(['model' => 'openrouter:x/y'], $spans[1]->attrs);
    }

    public function testForgottenTextLeavesKeptPrompts(): void {
        $run = $this->traces->openRun($this->runId, 'задача');
        $span = $this->traces->record($this->step(TraceSpan::MODEL, 'openrouter:x/y', $run));
        $line = '[#7] Кофе «иргачеффе», без сахара';
        $this->traces->payload($span, ['messages' => [['role' => 'user', 'content' => "вопрос
" . $line]]], ['text' => 'ответ про ' . $line]);

        self::assertSame(1, $this->traces->redactPayloads($line, '[#7] (забыто)'));
        $payload = $this->traces->payloadOf($span);
        self::assertSame(['text' => 'ответ про [#7] (забыто)'], $payload['response'] ?? null);
        self::assertSame(['messages' => [['role' => 'user', 'content' => "вопрос\n[#7] (забыто)"]]], $this->traces->payloadOf($span)['request'] ?? null);
        self::assertSame(0, $this->traces->redactPayloads($line, '[#7] (забыто)'));
    }

    public function testOldPayloadsArePurgedStepsStay(): void {
        $run = $this->traces->openRun($this->runId, 'задача');
        $span = $this->traces->record($this->step(TraceSpan::MODEL, 'openrouter:x/y', $run));
        $this->traces->payload($span, ['messages' => [['role' => 'user', 'content' => 'привет']]], ['text' => 'здравствуйте']);

        self::assertSame(['text' => 'здравствуйте'], $this->traces->payloadOf($span)['response'] ?? null);
        self::assertSame(0, $this->traces->purgePayloads(new DateTimeImmutable('-1 day')));
        self::assertSame(1, $this->traces->purgePayloads(new DateTimeImmutable('+1 minute')));

        self::assertNull($this->traces->payloadOf($span));
        self::assertCount(2, $this->traces->spans($this->runId));
    }
}
