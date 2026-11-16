<?php

declare(strict_types=1);

namespace OpenCCK\Test\Unit\App\Agent;

use Amp\DeferredCancellation;
use Amp\NullCancellation;
use Amp\PHPUnit\AsyncTestCase;
use Mercurius\Llm\Message;
use Mercurius\Llm\Role as LlmRole;
use Mercurius\Llm\ToolCall;
use OpenCCK\App\Agent\AgentLoop;
use OpenCCK\App\Agent\ShutdownException;
use OpenCCK\App\Agent\Tool\ToolExecutor;
use OpenCCK\App\Agent\Tool\ToolRegistry;
use OpenCCK\App\Agent\Tool\ToolResult;
use OpenCCK\Domain\Agent\DangerClass;
use OpenCCK\Domain\Agent\ParticipantPolicy;
use OpenCCK\Domain\Agent\Run;
use OpenCCK\Domain\Agent\RunLimits;
use OpenCCK\Domain\Agent\RunStatus;
use OpenCCK\Domain\Agent\StopReason;
use OpenCCK\Domain\Entity\Principal;
use OpenCCK\Domain\Entity\PrincipalKind;
use OpenCCK\Domain\Entity\Role;
use OpenCCK\Test\Support\FakeTool;
use OpenCCK\Test\Support\InMemoryConversationRepository;
use OpenCCK\Test\Support\InMemoryLlmCallRepository;
use OpenCCK\Test\Support\InMemoryRunRepository;
use OpenCCK\Test\Support\InMemoryTranscriptRepository;
use OpenCCK\Test\Support\RecordingObserver;
use OpenCCK\Test\Support\ScriptedLlm;
use RuntimeException;

use function Amp\async;
use function Amp\delay;

final class AgentLoopTest extends AsyncTestCase {
    private InMemoryRunRepository $runs;
    private InMemoryTranscriptRepository $transcript;
    private InMemoryConversationRepository $conversations;
    private InMemoryLlmCallRepository $calls;
    private ToolRegistry $registry;
    private RecordingObserver $observer;
    private Principal $owner;
    private int $conversationId;

    protected function setUp(): void {
        parent::setUp();
        $this->runs = new InMemoryRunRepository();
        $this->transcript = new InMemoryTranscriptRepository();
        $this->conversations = new InMemoryConversationRepository();
        $this->calls = new InMemoryLlmCallRepository();
        $this->registry = new ToolRegistry();
        $this->observer = new RecordingObserver();
        $this->owner = new Principal(1, PrincipalKind::Human, Role::Owner, null);
        $this->conversationId = $this->conversations->openConversation(1, 'test', '1');
        $this->conversations->setMeta($this->conversationId, 'системный промпт', 'test/model');
    }

    /** @param positive-int $maxTokens */
    private function loop(ScriptedLlm $llm, int $maxTokens = 100): AgentLoop {
        return new AgentLoop($llm, $this->runs, $this->transcript, $this->conversations, $this->calls, $this->registry, new ToolExecutor(), $maxTokens, null, 10);
    }

    private function start(string $task = 'задача', ?RunLimits $limits = null): Run {
        $run = $this->runs->start($this->conversationId, 1, 'test/model', $limits ?? new RunLimits(30, 100_000, 900, 120));
        self::assertNotNull($run);
        $this->transcript->appendUser($this->conversationId, $run->id, $task);
        return $run;
    }

    /** @param positive-int $maxTokens */
    private function execute(ScriptedLlm $llm, Run $run, int $maxTokens = 100, ?\Amp\Cancellation $cancellation = null, bool $readOnly = false): Run {
        $policy = new ParticipantPolicy(null, $run->limits, null, true, $readOnly);
        return $this->loop($llm, $maxTokens)->run($run, $policy, $this->owner, $this->observer, $cancellation ?? new NullCancellation());
    }

    /** @return list<Message> */
    private static function lastMessages(ScriptedLlm $llm): array {
        $request = end($llm->requests);
        self::assertNotFalse($request);
        return $request->messages;
    }

    public function testTwoStepTaskUsesToolsThenAnswers(): void {
        $this->registry->register(new FakeTool('search'));
        $this->registry->register(new FakeTool('save', DangerClass::Write));
        $llm = new ScriptedLlm([
            ScriptedLlm::calls(new ToolCall('c1', 'search', '{"q":"a"}')),
            ScriptedLlm::calls(new ToolCall('c2', 'save', '{"q":"b"}')),
            ScriptedLlm::text('Готово: сохранил.'),
        ]);

        $run = $this->execute($llm, $this->start());

        self::assertSame(RunStatus::Done, $run->status);
        self::assertSame(StopReason::Answered, $run->stopReason);
        self::assertSame(['search', 'save'], $this->observer->tools);
        self::assertSame('Готово: сохранил.', $this->observer->final);
        self::assertSame(3, $run->turns);
        self::assertSame('системный промпт', $llm->requests[0]->messages[0]->content);
        self::assertSame(['search', 'save'], array_map(static fn($d) => $d->name, $llm->requests[0]->tools));
        self::assertCount(3, $this->calls->calls);
        self::assertSame('agent', $this->calls->calls[0]->purpose);
    }

    public function testToolErrorGoesBackToModel(): void {
        $this->registry->register(new FakeTool('t', handler: static fn(): ToolResult => ToolResult::error('нет доступа')));
        $llm = new ScriptedLlm([ScriptedLlm::calls(new ToolCall('c1', 't', '{}')), ScriptedLlm::text('не вышло')]);

        $run = $this->execute($llm, $this->start());

        self::assertSame(RunStatus::Done, $run->status);
        $tool = self::lastMessages($llm)[3];
        self::assertSame(LlmRole::Tool, $tool->role);
        self::assertSame('нет доступа', $tool->content);
    }

    public function testUnknownToolGetsErrorResultAndRunContinues(): void {
        $this->registry->register(new FakeTool('t'));
        $llm = new ScriptedLlm([ScriptedLlm::calls(new ToolCall('c1', 'nope', '{}')), ScriptedLlm::text('ок')]);

        $run = $this->execute($llm, $this->start());

        self::assertSame(RunStatus::Done, $run->status);
        self::assertStringStartsWith('Неизвестный инструмент «nope»', self::lastMessages($llm)[3]->content);
    }

    public function testNothingExecutesWhenPersistFails(): void {
        $tool = new FakeTool('t');
        $this->registry->register($tool);
        $this->transcript->beforeAssistant = static function (): void {
            throw new RuntimeException('db down');
        };
        $llm = new ScriptedLlm([ScriptedLlm::calls(new ToolCall('c1', 't', '{}'))]);

        $run = $this->execute($llm, $this->start());

        self::assertSame(StopReason::PersistFailed, $run->stopReason);
        self::assertSame([], $tool->calls);
    }

    public function testTruncatedArgumentsRetryOnceThenStop(): void {
        $tool = new FakeTool('t');
        $this->registry->register($tool);
        $cut = ScriptedLlm::truncated(new ToolCall('c1', 't', '{"q": "ab'));
        $llm = new ScriptedLlm([$cut, $cut]);

        $run = $this->execute($llm, $this->start());

        self::assertSame(StopReason::Truncated, $run->stopReason);
        self::assertCount(2, $llm->requests);
        self::assertSame([], $tool->calls);
    }

    public function testTurnLimitGivesFinalSummaryAndReport(): void {
        $this->registry->register(new FakeTool('t'));
        $llm = new ScriptedLlm([
            ScriptedLlm::calls(new ToolCall('c1', 't', '{"q":"1"}')),
            ScriptedLlm::calls(new ToolCall('c2', 't', '{"q":"2"}')),
            ScriptedLlm::text('Сделал два шага.'),
        ]);

        $run = $this->execute($llm, $this->start(limits: new RunLimits(2, 100_000, 900, 120)));

        self::assertSame(StopReason::TurnLimit, $run->stopReason);
        self::assertSame(RunStatus::Failed, $run->status);
        self::assertSame([], $llm->requests[2]->tools);
        $messages = self::lastMessages($llm);
        $closing = end($messages);
        self::assertNotFalse($closing);
        self::assertStringContainsString('Лимит исчерпан (исчерпан лимит ходов)', $closing->content);
        self::assertStringStartsWith('Сделал два шага.', (string) $this->observer->final);
        self::assertStringContainsString('Остановлено: исчерпан лимит ходов. Ходов: 3', (string) $this->observer->final);
    }

    public function testHungModelCallEndsAtTheDeadline(): void {
        // A provider that keeps the connection alive but never answers must not hold the run past its deadline.
        $llm = new ScriptedLlm([ScriptedLlm::text('слишком поздно'), ScriptedLlm::text('итог')]);
        $llm->delay = 3.0;
        $started = microtime(true);

        $run = $this->execute($llm, $this->start(limits: new RunLimits(30, 100_000, 1, 120)));

        self::assertSame(StopReason::Deadline, $run->stopReason);
        self::assertLessThan(5.0, microtime(true) - $started, 'the first call is cut at 1 s, the summary takes 3 s');
        self::assertCount(2, $llm->requests);
        self::assertSame([], $llm->requests[1]->tools);
    }

    public function testReadOnlyPolicyRefusesEveryCallThatWrites(): void {
        $write = new FakeTool('w', DangerClass::Write);
        $read = new FakeTool('r');
        $this->registry->register($write);
        $this->registry->register($read);
        $llm = new ScriptedLlm([
            ScriptedLlm::calls(new ToolCall('c1', 'w', '{"q":"1"}'), new ToolCall('c2', 'r', '{"q":"2"}')),
            ScriptedLlm::text('итог'),
        ]);

        $this->execute($llm, $this->start(), readOnly: true);

        self::assertSame([], $write->calls, 'the write never ran');
        self::assertCount(1, $read->calls);
        $refusals = array_filter(self::lastMessages($llm), static fn(Message $m): bool => str_contains($m->content, 'только чтение'));
        self::assertCount(1, $refusals);
    }

    public function testCutOffAnswerIsContinuedAndStitched(): void {
        $llm = new ScriptedLlm([ScriptedLlm::cut('Первая часть, '), ScriptedLlm::text('вторая часть.')]);

        $run = $this->execute($llm, $this->start());

        self::assertSame(StopReason::Answered, $run->stopReason);
        self::assertSame('Первая часть, вторая часть.', $this->observer->final);
        $tail = array_slice($llm->requests[1]->messages, -2);
        self::assertSame(LlmRole::Assistant, $tail[0]->role);
        self::assertSame('Первая часть, ', $tail[0]->content);
        self::assertSame(AgentLoop::CONTINUE_NOTE, $tail[1]->content);
        $saved = $this->transcript->runMessages($run->id);
        $last = end($saved);
        self::assertNotFalse($last);
        self::assertSame('Первая часть, вторая часть.', $last->content, 'one stitched answer in the history');
    }

    public function testEmptyAnswerIsRetriedWithAHint(): void {
        $llm = new ScriptedLlm([ScriptedLlm::text(''), ScriptedLlm::text('Ответ.')]);

        $run = $this->execute($llm, $this->start());

        self::assertSame(StopReason::Answered, $run->stopReason);
        self::assertSame('Ответ.', $this->observer->final);
        $messages = $llm->requests[1]->messages;
        $last = end($messages);
        self::assertNotFalse($last);
        self::assertSame(AgentLoop::EMPTY_NOTE, $last->content);
    }

    public function testEmptyAnswersGiveUpAfterThreeRetries(): void {
        $llm = new ScriptedLlm([ScriptedLlm::text(''), ScriptedLlm::text(''), ScriptedLlm::text(''), ScriptedLlm::text(''), ScriptedLlm::text('лишний')]);

        $this->execute($llm, $this->start());

        self::assertCount(4, $llm->requests);
        self::assertStringContainsString('пустой ответ', (string) $this->observer->final);
    }

    public function testBrokenStreamIsRetriedOnce(): void {
        $down = new \Mercurius\Llm\LlmException(\Mercurius\Llm\ErrorKind::Network, 'reset');
        $llm = new ScriptedLlm([$down, ScriptedLlm::text('Ответ.')]);

        $run = $this->execute($llm, $this->start());

        self::assertSame(StopReason::Answered, $run->stopReason);
        self::assertSame('Ответ.', $this->observer->final);
    }

    public function testTokenBudgetStopsBeforeModelCall(): void {
        $llm = new ScriptedLlm([ScriptedLlm::text('итог')]);

        $run = $this->execute($llm, $this->start(limits: new RunLimits(30, 50, 900, 120)), maxTokens: 100);

        self::assertSame(StopReason::TokenBudget, $run->stopReason);
        self::assertCount(1, $llm->requests);
        self::assertSame([], $llm->requests[0]->tools);
    }

    public function testEightyPercentWarningIsAddedOnce(): void {
        $this->registry->register(new FakeTool('t'));
        $script = [];
        for ($i = 1; $i <= 4; $i++) {
            $script[] = ScriptedLlm::calls(new ToolCall('c' . $i, 't', '{"q":"' . $i . '"}'));
        }
        $script[] = ScriptedLlm::text('итог');
        $llm = new ScriptedLlm($script);

        $run = $this->execute($llm, $this->start(limits: new RunLimits(5, 100_000, 900, 120)));

        self::assertSame(RunStatus::Done, $run->status);
        $warnings = array_filter(self::lastMessages($llm), static fn(Message $m) => str_contains($m->content, 'Подводи итог'));
        self::assertCount(1, $warnings);
    }

    public function testLoopGuardHaltsOnFifthRepeat(): void {
        $this->registry->register(new FakeTool('t'));
        $script = [];
        for ($i = 1; $i <= 6; $i++) {
            $script[] = ScriptedLlm::calls(new ToolCall('c' . $i, 't', '{"q":"same"}'));
        }
        $llm = new ScriptedLlm($script);

        $run = $this->execute($llm, $this->start());

        self::assertSame(StopReason::LoopGuard, $run->stopReason);
        self::assertSame(5, $run->turns);
    }

    public function testInboxMessageIsDeliveredAfterToolResults(): void {
        $runRef = $this->start();
        $runId = $runRef->id;
        $this->registry->register(new FakeTool('t', handler: function () use ($runId): ToolResult {
            $this->transcript->inbox($runId, 'и ещё проверь X');
            return ToolResult::ok('r');
        }));
        $llm = new ScriptedLlm([ScriptedLlm::calls(new ToolCall('c1', 't', '{}')), ScriptedLlm::text('ок')]);

        $this->execute($llm, $runRef);

        $messages = self::lastMessages($llm);
        self::assertSame(LlmRole::Tool, $messages[3]->role);
        self::assertSame(LlmRole::User, $messages[4]->role);
        self::assertSame('и ещё проверь X', $messages[4]->content);
    }

    public function testShutdownLeavesRunRunning(): void {
        $this->registry->register(new FakeTool('slow', delay: 1.0));
        $llm = new ScriptedLlm([ScriptedLlm::calls(new ToolCall('c1', 'slow', '{}'))]);
        $deferred = new DeferredCancellation();
        $run = $this->start();
        $future = async(fn(): Run => $this->execute($llm, $run, cancellation: $deferred->getCancellation()));
        delay(0.1);
        $deferred->cancel(new ShutdownException());

        $result = $future->await();
        self::assertInstanceOf(Run::class, $result);

        self::assertSame(RunStatus::Running, $result->status);
        self::assertNull($this->observer->final);
    }

    public function testStopMarksRunStoppedAndCallInterrupted(): void {
        $this->registry->register(new FakeTool('slow', delay: 1.0));
        $llm = new ScriptedLlm([ScriptedLlm::calls(new ToolCall('c1', 'slow', '{}'))]);
        $deferred = new DeferredCancellation();
        $run = $this->start();
        $future = async(fn(): Run => $this->execute($llm, $run, cancellation: $deferred->getCancellation()));
        delay(0.1);
        $deferred->cancel();

        $result = $future->await();
        self::assertInstanceOf(Run::class, $result);

        self::assertSame(RunStatus::Stopped, $result->status);
        self::assertSame(StopReason::Stopped, $result->stopReason);
        self::assertSame(\OpenCCK\Domain\Agent\ToolCallStatus::Interrupted, $this->transcript->status($run->id, 'c1'));
    }

    public function testReadCallsRunInParallel(): void {
        $this->registry->register(new FakeTool('a', delay: 1.0));
        $this->registry->register(new FakeTool('b', delay: 1.0));
        $llm = new ScriptedLlm([ScriptedLlm::calls(new ToolCall('c1', 'a', '{}'), new ToolCall('c2', 'b', '{}')), ScriptedLlm::text('ок')]);

        $started = microtime(true);
        $this->execute($llm, $this->start());

        self::assertLessThan(1.5, microtime(true) - $started);
    }

    public function testPendingCallsAreExecutedFirstOnResume(): void {
        $tool = new FakeTool('t');
        $this->registry->register($tool);
        $run = $this->start();
        $this->transcript->appendAssistant($this->conversationId, $run->id, '', [
            new \OpenCCK\Domain\Agent\StoredToolCall('c1', 0, 't', ['q' => 'x'], '{"q":"x"}', DangerClass::Read, \OpenCCK\Domain\Agent\ToolCallStatus::Pending),
        ]);
        $llm = new ScriptedLlm([ScriptedLlm::text('ок')]);

        $result = $this->execute($llm, $run);

        self::assertSame(RunStatus::Done, $result->status);
        self::assertSame([['q' => 'x']], $tool->calls);
        self::assertSame(LlmRole::Tool, $llm->requests[0]->messages[3]->role);
    }
}
