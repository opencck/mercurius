<?php

declare(strict_types=1);

namespace OpenCCK\Test\Unit\App\Agent\Trace;

use Amp\NullCancellation;
use Amp\PHPUnit\AsyncTestCase;
use Mercurius\Llm\ChatResponse;
use Mercurius\Llm\FinishReason;
use Mercurius\Llm\ModelCatalog\ModelInfo;
use Mercurius\Llm\ToolCall;
use Mercurius\Llm\Usage;
use OpenCCK\App\Agent\AgentLoop;
use OpenCCK\App\Agent\Trace\Tracer;
use OpenCCK\App\Agent\Tool\ToolExecutor;
use OpenCCK\App\Agent\Tool\ToolRegistry;
use OpenCCK\Domain\Agent\ParticipantPolicy;
use OpenCCK\Domain\Agent\Run;
use OpenCCK\Domain\Agent\RunLimits;
use OpenCCK\Domain\Agent\RunStatus;
use OpenCCK\Domain\Agent\TraceSpan;
use OpenCCK\Domain\Entity\Principal;
use OpenCCK\Domain\Entity\PrincipalKind;
use OpenCCK\Domain\Entity\Role;
use OpenCCK\Test\Support\FakeTool;
use OpenCCK\Test\Support\InMemoryConversationRepository;
use OpenCCK\Test\Support\InMemoryLlmCallRepository;
use OpenCCK\Test\Support\InMemoryModelStores;
use OpenCCK\Test\Support\InMemoryRunRepository;
use OpenCCK\Test\Support\InMemoryTraceRepository;
use OpenCCK\Test\Support\InMemoryTranscriptRepository;
use OpenCCK\Test\Support\RecordingObserver;
use OpenCCK\Test\Support\ScriptedLlm;
use Psr\Log\NullLogger;

/**
 * Traces of a run: one run step, a step per model call and per tool, masked payloads, cost with its status.
 */
final class TracerTest extends AsyncTestCase {
    private InMemoryRunRepository $runs;
    private InMemoryTranscriptRepository $transcript;
    private InMemoryTraceRepository $traces;
    private InMemoryModelStores $catalog;
    private ToolRegistry $registry;

    protected function setUp(): void {
        parent::setUp();
        $this->runs = new InMemoryRunRepository();
        $this->transcript = new InMemoryTranscriptRepository();
        $this->traces = new InMemoryTraceRepository();
        $this->catalog = new InMemoryModelStores();
        $this->registry = new ToolRegistry();
        $this->registry->register(new FakeTool('read'));
    }

    private function execute(ScriptedLlm $llm, string $task = 'задача', ?Run $run = null): Run {
        $conversations = new InMemoryConversationRepository();
        $conversations->openConversation(1, 'test', '1');
        $tracer = new Tracer($this->traces, $this->catalog, new NullLogger());
        $loop = new AgentLoop($llm, $this->runs, $this->transcript, $conversations, new InMemoryLlmCallRepository(), $this->registry, new ToolExecutor(), 100, null, 10, events: $tracer);
        if ($run === null) {
            $run = $this->runs->start(1, 1, 'openrouter:x/model', new RunLimits(30, 100_000, 900, 120));
            self::assertNotNull($run);
            $this->transcript->appendUser(1, $run->id, $task);
        }
        $owner = new Principal(1, PrincipalKind::Human, Role::owner(), null);
        $final = $loop->run($run, new ParticipantPolicy(null, $run->limits, null, false), $owner, new RecordingObserver(), new NullCancellation());
        $tracer->idle();
        return $final;
    }

    private static function priced(string $text, ToolCall ...$calls): ChatResponse {
        return new ChatResponse($text, array_values($calls), $calls === [] ? FinishReason::Stop : FinishReason::ToolCalls, new Usage(1000, 100, 200, 0, 0, 0.0021), 'x/model', 'gen');
    }

    /** @return list<string> */
    private function kinds(int $runId): array {
        return array_map(static fn(TraceSpan $s): string => $s->kind . ':' . $s->name, $this->traces->spans($runId));
    }

    public function testRunHasAChainOfSteps(): void {
        $final = $this->execute(new ScriptedLlm([self::priced('', new ToolCall('c1', 'read', '{"q":"a"}')), self::priced('готово')]));

        self::assertSame(RunStatus::Done, $final->status);
        self::assertSame(['run:openrouter:x/model', 'model:openrouter:x/model', 'tool:read', 'model:openrouter:x/model'], $this->kinds($final->id));
        $spans = $this->traces->spans($final->id);
        self::assertSame(TraceSpan::OK, $spans[0]->status);
        self::assertNotNull($spans[0]->durationMs);
        self::assertSame($spans[0]->id, $spans[1]->parentId);
        self::assertSame(1000, $spans[1]->inputTokens);
        self::assertSame(200, $spans[1]->cacheReadTokens);
        self::assertSame(TraceSpan::COST_ACTUAL, $spans[1]->costStatus);
        self::assertEqualsWithDelta(0.0021, $spans[1]->costUsd, 0.0000001);
        self::assertSame(TraceSpan::OK, $spans[2]->status);
    }

    public function testPayloadsAreMasked(): void {
        $this->transcript = new InMemoryTranscriptRepository();
        $final = $this->execute(new ScriptedLlm([self::priced('', new ToolCall('c1', 'read', '{"q":"password=hunter2"}')), self::priced('ключ sk-proj-ABCDEFGHIJKLMNOPQRSTUV')]), 'мой токен ghp_0123456789abcdefghijABCDEFGHIJ0123');

        $spans = $this->traces->spans($final->id);
        $payload = json_encode([$this->traces->payloadOf((int) $spans[1]->id), $this->traces->payloadOf((int) $spans[3]->id)], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        self::assertStringNotContainsString('hunter2', $payload);
        self::assertStringNotContainsString('ghp_0123456789', $payload);
        self::assertStringNotContainsString('sk-proj-ABCDEFGHIJKLMNOPQRSTUV', $payload);
        self::assertStringContainsString('мой токен', $payload);
    }

    public function testEachPayloadKeepsOnlyNewMessages(): void {
        $final = $this->execute(new ScriptedLlm([self::priced('', new ToolCall('c1', 'read', '{}')), self::priced('готово')]));

        $spans = $this->traces->spans($final->id);
        $first = $this->traces->payloadOf((int) $spans[1]->id)['request'] ?? [];
        $second = $this->traces->payloadOf((int) $spans[3]->id)['request'] ?? [];
        self::assertSame(0, $first['from'] ?? null);
        $firstJson = json_encode($first, JSON_THROW_ON_ERROR);
        self::assertStringContainsString('"role":"system"', $firstJson);
        self::assertStringContainsString('"role":"user"', $firstJson);
        self::assertSame(2, $second['from'] ?? null);
        $secondJson = json_encode($second, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('"role":"system"', $secondJson);
        self::assertStringNotContainsString('"role":"user"', $secondJson);
        self::assertStringContainsString('"role":"tool"', $secondJson);
    }

    public function testClosedRunIsNotReopenedByALateEvent(): void {
        $final = $this->execute(new ScriptedLlm([self::priced('готово')]));
        $tracer = new Tracer($this->traces, $this->catalog, new NullLogger());

        $tracer->approvalDecided($final->id, 'push', 'denied', 1.0);
        $tracer->idle();

        self::assertSame(TraceSpan::OK, $this->traces->spans($final->id)[0]->status);
    }

    public function testAWorkerIsAStepOfItsOrchestrator(): void {
        // spec: brief/operations.md#трассировка-и-метрики — nested traces: a worker step points at the worker's run
        $orchestrator = $this->execute(new ScriptedLlm([self::priced('готово')]));
        $tracer = new Tracer($this->traces, $this->catalog, new NullLogger());

        $tracer->workerFinished($orchestrator->id, 77, 'openrouter:x/worker', 'done', 2.5);
        $tracer->idle();

        $worker = array_values(array_filter($this->traces->spans($orchestrator->id), static fn(TraceSpan $s): bool => $s->kind === TraceSpan::WORKER))[0] ?? null;
        self::assertNotNull($worker);
        self::assertSame(['#77', 2500, TraceSpan::OK, 77], [$worker->name, $worker->durationMs, $worker->status, $worker->attrs['run'] ?? null]);
    }

    public function testEstimatedCostFromTheCatalog(): void {
        $this->catalog->replace('openrouter', [new ModelInfo('x/model', 'x', 100_000, 0.000001, 0.000002)]);
        $unpriced = new ChatResponse('готово', [], FinishReason::Stop, new Usage(1000, 100), 'x/model', 'gen');

        $final = $this->execute(new ScriptedLlm([$unpriced]));

        $model = $this->traces->spans($final->id)[1];
        self::assertSame(TraceSpan::COST_ESTIMATED, $model->costStatus);
        self::assertEqualsWithDelta(0.0012, $model->costUsd, 0.0000001);
    }

    public function testTraceFailureDoesNotBreakTheRun(): void {
        $this->traces->failing = true;

        $final = $this->execute(new ScriptedLlm([self::priced('', new ToolCall('c1', 'read', '{}')), self::priced('готово')]));

        self::assertSame(RunStatus::Done, $final->status);
    }

    public function testResumedRunAddsToTheSameTrace(): void {
        $run = $this->runs->start(1, 1, 'openrouter:x/model', new RunLimits(30, 100_000, 900, 120));
        self::assertNotNull($run);
        $this->transcript->appendUser(1, $run->id, 'задача');
        $this->traces->openRun($run->id, 'openrouter:x/model');

        $this->execute(new ScriptedLlm([self::priced('готово')]), run: $run);

        self::assertSame(['run:openrouter:x/model', 'model:openrouter:x/model'], $this->kinds($run->id));
    }
}
