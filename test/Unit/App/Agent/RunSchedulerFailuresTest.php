<?php

declare(strict_types=1);

namespace OpenCCK\Test\Unit\App\Agent;

use Amp\PHPUnit\AsyncTestCase;
use Mercurius\Llm\ToolCall;
use OpenCCK\App\Agent\AgentLoop;
use OpenCCK\App\Agent\RunScheduler;
use OpenCCK\Domain\Agent\RunLimits;
use OpenCCK\Domain\Agent\RunStatus;
use OpenCCK\Domain\Agent\StopReason;
use OpenCCK\Test\Support\AgentStack;
use OpenCCK\Test\Support\FakeTool;
use OpenCCK\Test\Support\ScriptedLlm;
use RuntimeException;

use function Amp\delay;

/**
 * Failure paths found by the final review: crashed runs, stop during the summary, overlapping runs, lost messages.
 */
final class RunSchedulerFailuresTest extends AsyncTestCase {
    private static function status(AgentStack $stack, int $runId): ?RunStatus {
        return $stack->runs->find($runId)?->status;
    }

    /** C1: an error inside the loop must not leave a running run without a fiber. */
    public function testCrashedRunIsFailedAndConversationIsFree(): void {
        $stack = new AgentStack(new ScriptedLlm([ScriptedLlm::calls(new ToolCall('c1', 't', '{}')), ScriptedLlm::text('второй')]));
        $stack->registry->register(new FakeTool('t'));
        $stack->transcript->beforeToolResult = static function (): void {
            throw new RuntimeException('db down');
        };

        $stack->say('задача');
        $stack->scheduler->awaitIdle();

        self::assertSame(RunStatus::Failed, self::status($stack, 1));
        $edits = $stack->channel->texts('edit');
        self::assertSame(RunScheduler::CRASHED, end($edits));

        $stack->transcript->beforeToolResult = null;
        $stack->say('ещё раз');
        $stack->scheduler->awaitIdle();
        self::assertSame(RunStatus::Done, self::status($stack, 2));
        $contents = array_map(static fn($m) => $m->content, array_slice($stack->llm->requests[1]->messages, 1));
        self::assertSame(['задача', RunScheduler::CRASHED, 'ещё раз'], $contents);
    }

    /** C1: /stop during the closing summary call stops the run instead of crashing it. */
    public function testStopDuringSummaryStopsTheRun(): void {
        $llm = new ScriptedLlm([ScriptedLlm::text('итог')]);
        $stack = new AgentStack($llm, new RunLimits(30, 50, 900, 120));
        $llm->delay = 1.0;

        $stack->say('задача');
        delay(0.2);
        self::assertTrue($stack->scheduler->stop(1));
        $stack->scheduler->awaitIdle();

        self::assertSame(RunStatus::Stopped, self::status($stack, 1));
        self::assertSame(StopReason::Stopped, $stack->runs->find(1)?->stopReason);
    }

    /** I1: the old run's fiber must not drop the entry of a run started while it was delivering the answer. */
    public function testOldFiberDoesNotDropNewRun(): void {
        $stack = new AgentStack(new ScriptedLlm([
            ScriptedLlm::text(str_repeat('слово ', 40)),
            ScriptedLlm::calls(new ToolCall('c1', 'slow', '{}')),
        ]), maxMessageLength: 50);
        $stack->registry->register(new FakeTool('slow', delay: 3.0));
        $stack->channel->sendDelay = 0.1;

        $stack->say('длинный ответ');
        delay(0.25);
        $stack->say('вторая задача');
        delay(1.0);

        self::assertSame(RunStatus::Running, self::status($stack, 2));
        self::assertTrue($stack->scheduler->stop(1));
        $stack->scheduler->awaitIdle();
        self::assertSame(RunStatus::Stopped, self::status($stack, 2));
    }

    /** Found on the server: a stopped task must look closed in the history, or the next run picks it up again. */
    public function testStoppedTaskIsClosedInHistory(): void {
        $stack = new AgentStack(new ScriptedLlm([ScriptedLlm::calls(new ToolCall('c1', 'slow', '{}')), ScriptedLlm::text('новое')]));
        $stack->registry->register(new FakeTool('slow', delay: 5.0));

        $stack->say('старая задача');
        delay(0.1);
        $stack->say('/stop');
        $stack->scheduler->awaitIdle();
        $stack->say('новая задача');
        $stack->scheduler->awaitIdle();

        $contents = array_map(static fn($m) => $m->content, array_slice($stack->llm->requests[1]->messages, 1));
        self::assertSame(['старая задача', AgentLoop::STOPPED_NOTE, 'новая задача'], $contents);
    }

    /** A run that failed for a reason other than a limit is closed in the history as well. */
    public function testFailedTaskIsClosedInHistory(): void {
        $stack = new AgentStack(new ScriptedLlm([new \Mercurius\Llm\LlmException(\Mercurius\Llm\ErrorKind::Network, 'down'), new \Mercurius\Llm\LlmException(\Mercurius\Llm\ErrorKind::Network, 'down'), ScriptedLlm::text('ок')]));

        $stack->say('первая');
        $stack->scheduler->awaitIdle();
        $stack->say('вторая');
        $stack->scheduler->awaitIdle();

        $contents = array_map(static fn($m) => $m->content, array_slice($stack->llm->requests[2]->messages, 1));
        self::assertSame('первая', $contents[0]);
        self::assertStringStartsWith('Не удалось завершить задачу.', $contents[1]);
        self::assertSame('вторая', $contents[2]);
    }

    /** I2: a failure while starting a run is reported, not thrown into the event loop. */
    public function testFailureWhileStartingIsReported(): void {
        $stack = new AgentStack(new ScriptedLlm([ScriptedLlm::text('ок')]));
        $stack->transcript->beforeUser = static function (): void {
            throw new RuntimeException('db down');
        };

        $stack->say('задача');
        $stack->scheduler->awaitIdle();

        self::assertContains(RunScheduler::NOT_STARTED, $stack->channel->texts('send'));
    }

    /** I4: a message right after /stop starts a new run instead of vanishing into the stopped one. */
    public function testMessageRightAfterStopStartsNewRun(): void {
        $stack = new AgentStack(new ScriptedLlm([ScriptedLlm::calls(new ToolCall('c1', 'slow', '{}')), ScriptedLlm::text('новое')]));
        $stack->registry->register(new FakeTool('slow', delay: 5.0));

        $stack->say('задача');
        delay(0.1);
        $stack->say('/stop');
        $stack->say('новая задача');
        $stack->scheduler->awaitIdle();

        self::assertSame(RunStatus::Stopped, self::status($stack, 1));
        self::assertSame(RunStatus::Done, self::status($stack, 2));
        $messages = $stack->llm->requests[1]->messages;
        $last = end($messages);
        self::assertNotFalse($last);
        self::assertSame('новая задача', $last->content);
    }
}
