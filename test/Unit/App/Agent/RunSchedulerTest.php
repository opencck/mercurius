<?php

declare(strict_types=1);

namespace OpenCCK\Test\Unit\App\Agent;

use Amp\PHPUnit\AsyncTestCase;
use Mercurius\Llm\Role as LlmRole;
use Mercurius\Llm\ToolCall;
use Mercurius\Messenger\ChatDispatcher;
use OpenCCK\App\Agent\Tool\ToolResult;
use OpenCCK\Domain\Agent\DangerClass;
use OpenCCK\Domain\Agent\RunStatus;
use OpenCCK\Domain\Agent\StopReason;
use OpenCCK\Domain\Agent\StoredToolCall;
use OpenCCK\Domain\Agent\ToolCallStatus;
use OpenCCK\Test\Support\AgentStack;
use OpenCCK\Test\Support\FakeTool;
use OpenCCK\Test\Support\ScriptedLlm;

use function Amp\delay;

final class RunSchedulerTest extends AsyncTestCase {
    public function testMessageStartsRunAndAnswerIsDelivered(): void {
        $stack = new AgentStack(new ScriptedLlm([ScriptedLlm::text('Привет!')]));

        $stack->say('привет');
        $stack->scheduler->awaitIdle();

        $edits = $stack->channel->texts('edit');
        self::assertSame('Привет!', end($edits));
        self::assertSame(RunStatus::Done, $stack->runs->runs[1]->status);
        self::assertNotNull($stack->conversations->meta(1)?->systemPrompt);
        self::assertSame(\OpenCCK\App\Service\SystemPrompt::VERSION, $stack->conversations->promptVersions[1] ?? null, 'traces can be grouped by prompt version');
    }

    public function testMessageDuringRunGoesToInbox(): void {
        $stack = new AgentStack(new ScriptedLlm([ScriptedLlm::calls(new ToolCall('c1', 'slow', '{}')), ScriptedLlm::text('ок')]));
        $stack->registry->register(new FakeTool('slow', delay: 0.3));

        $stack->say('задача');
        delay(0.1);
        $stack->say('и ещё X');
        $stack->scheduler->awaitIdle();

        self::assertCount(1, $stack->runs->runs);
        $messages = $stack->llm->requests[1]->messages;
        self::assertSame(LlmRole::Tool, $messages[3]->role);
        self::assertSame('и ещё X', $messages[4]->content);
    }

    public function testADeliveryToAConversationClosedByNewIsDropped(): void {
        // spec: BRIEF.md#часть-3--фоновое-делегирование — /new is a clean start: no old report runs in it
        $stack = new AgentStack(new ScriptedLlm([ScriptedLlm::text('ок'), ScriptedLlm::text('не должно')]));
        $stack->say('привет');
        $stack->scheduler->awaitIdle();
        $run = $stack->runs->find(1);
        self::assertNotNull($run);
        $meta = $stack->conversations->meta($run->conversationId);
        self::assertNotNull($meta);
        $principal = $stack->principals->find($run->principalId);
        self::assertNotNull($principal);
        $stack->conversations->close($meta->channel, $meta->chatId, $meta->threadId);
        $delivered = [];

        $stack->scheduler->deliver($principal, $run->conversationId, 'отчёт воркеров', static function (int $runId) use (&$delivered): void {
            $delivered[] = $runId;
        });
        $stack->scheduler->awaitIdle();

        self::assertSame([], $delivered);
        self::assertNull($stack->runs->find(2), 'no run in the closed conversation');
        self::assertCount(1, $stack->llm->requests);
    }

    public function testMessagesWithinDebounceAreMerged(): void {
        $stack = new AgentStack(new ScriptedLlm([ScriptedLlm::text('ок')]), debounce: 0.2);

        $stack->say('первое');
        delay(0.05);
        $stack->say('второе');
        $stack->scheduler->awaitIdle();

        self::assertCount(1, $stack->runs->runs);
        self::assertSame("первое\n\nвторое", $stack->llm->requests[0]->messages[1]->content);
    }

    public function testStopCancelsRunningToolAndMarksInterrupted(): void {
        $stack = new AgentStack(new ScriptedLlm([ScriptedLlm::calls(new ToolCall('c1', 'slow', '{}'))]));
        $stack->registry->register(new FakeTool('slow', delay: 5.0));

        $stack->say('задача');
        delay(0.1);
        self::assertTrue($stack->scheduler->stop(1));
        $stack->scheduler->awaitIdle();

        self::assertSame(RunStatus::Stopped, $stack->runs->runs[1]->status);
        self::assertSame(ToolCallStatus::Interrupted, $stack->transcript->status(1, 'c1'));
        self::assertFalse($stack->scheduler->stop(1));
    }

    public function testNewMessageAfterStopStartsNewRun(): void {
        $stack = new AgentStack(new ScriptedLlm([ScriptedLlm::calls(new ToolCall('c1', 'slow', '{}')), ScriptedLlm::text('новое')]));
        $stack->registry->register(new FakeTool('slow', delay: 5.0));

        $stack->say('задача');
        delay(0.1);
        $stack->scheduler->stop(1);
        $stack->scheduler->awaitIdle();
        $stack->say('другая задача');
        $stack->scheduler->awaitIdle();

        self::assertCount(2, $stack->runs->runs);
        self::assertSame(RunStatus::Done, $stack->runs->runs[2]->status);
    }

    private function interruptedRun(AgentStack $stack, DangerClass $danger): void {
        $stack->say('/start');
        $conversationId = $stack->conversations->openConversation(1, 'telegram', '42');
        $stack->conversations->setMeta($conversationId, 'промпт', 'test/model');
        $run = $stack->runs->start($conversationId, 1, 'test/model', new \OpenCCK\Domain\Agent\RunLimits(30, 100_000, 900, 120));
        self::assertNotNull($run);
        $stack->transcript->appendUser($conversationId, $run->id, 'задача');
        $stack->transcript->appendAssistant($conversationId, $run->id, '', [
            new StoredToolCall('c1', 0, 'tool', [], '{}', $danger, ToolCallStatus::Pending),
        ]);
        $stack->transcript->startCall($run->id, 'c1');
    }

    public function testWorkersAreNotResumed(): void {
        $stack = new AgentStack();
        $owner = $stack->principals->ensureOwner('telegram', '42', 'Owner');
        $conversation = $stack->conversations->openConversation($owner->id, 'worker', 'run1-d1-1');
        $parent = $stack->runs->start(1, $owner->id, 'm/x', new \OpenCCK\Domain\Agent\RunLimits(30, 1000, 900, 120));
        self::assertNotNull($parent);
        $stack->runs->finish($parent->id, RunStatus::Stopped, \OpenCCK\Domain\Agent\StopReason::Stopped);
        $worker = $stack->runs->start($conversation, $owner->id, 'm/x', new \OpenCCK\Domain\Agent\RunLimits(15, 1000, 840, 120), $parent->id);
        self::assertNotNull($worker);

        $stack->scheduler->resumeAll();
        $stack->scheduler->awaitIdle();

        self::assertSame(RunStatus::Failed, $stack->runs->find($worker->id)?->status);
        self::assertSame([], $stack->llm->requests);
        self::assertSame([], $stack->channel->texts('send'));
    }

    public function testResumeRerunsInterruptedRead(): void {
        $stack = new AgentStack(new ScriptedLlm([ScriptedLlm::text('продолжил')]));
        $tool = new FakeTool('tool');
        $stack->registry->register($tool);
        $this->interruptedRun($stack, DangerClass::Read);

        $stack->scheduler->resumeAll();
        $stack->scheduler->awaitIdle();

        self::assertCount(1, $tool->calls);
        self::assertSame(RunStatus::Done, $stack->runs->runs[1]->status);
        self::assertContains('Продолжаю задачу после перезапуска.', $stack->channel->texts('send'));
    }

    public function testResumeDoesNotRepeatInterruptedWrite(): void {
        $stack = new AgentStack(new ScriptedLlm([ScriptedLlm::text('проверю')]));
        $tool = new FakeTool('tool', DangerClass::Write);
        $stack->registry->register($tool);
        $this->interruptedRun($stack, DangerClass::Write);

        $stack->scheduler->resumeAll();
        $stack->scheduler->awaitIdle();

        self::assertSame([], $tool->calls);
        self::assertSame(ToolCallStatus::Interrupted, $stack->transcript->status(1, 'c1'));
        self::assertStringStartsWith('Прервано перезапуском', $stack->llm->requests[0]->messages[3]->content);
    }

    public function testShutdownKeepsRunResumable(): void {
        $stack = new AgentStack(new ScriptedLlm([ScriptedLlm::calls(new ToolCall('c1', 'slow', '{}'))]));
        $stack->registry->register(new FakeTool('slow', delay: 5.0));

        $stack->say('задача');
        delay(0.1);
        $stack->scheduler->shutdown();

        self::assertSame(RunStatus::Running, $stack->runs->runs[1]->status);
        self::assertCount(1, $stack->runs->unfinished());
    }

    public function testStopIsHandledWhileRunIsBusy(): void {
        $stack = new AgentStack(new ScriptedLlm([ScriptedLlm::calls(new ToolCall('c1', 'slow', '{}'))]));
        $stack->registry->register(new FakeTool('slow', delay: 5.0));
        $dispatcher = new ChatDispatcher($stack->chat);

        $dispatcher->dispatch($stack->message('задача'));
        delay(0.1);
        $dispatcher->dispatch($stack->message('/stop'));
        $dispatcher->awaitIdle();
        $stack->scheduler->awaitIdle();

        self::assertSame(StopReason::Stopped, $stack->runs->runs[1]->stopReason);
        self::assertContains('Остановлено.', $stack->channel->texts('edit'));
    }

    public function testToolStepIsShownInStatus(): void {
        $stack = new AgentStack(new ScriptedLlm([ScriptedLlm::calls(new ToolCall('c1', 'search', '{"q":"x"}')), ScriptedLlm::text('ок')]));
        $stack->registry->register(new FakeTool('search', handler: static fn(): ToolResult => ToolResult::ok('r')));

        $stack->say('найди');
        $stack->scheduler->awaitIdle();

        self::assertContains('Выполняю search: {"q":"x"} …', $stack->channel->texts('edit'));
    }
}
