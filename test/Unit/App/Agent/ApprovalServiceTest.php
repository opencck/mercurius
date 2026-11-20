<?php

declare(strict_types=1);

namespace OpenCCK\Test\Unit\App\Agent;

use Amp\PHPUnit\AsyncTestCase;
use Mercurius\Llm\ToolCall;
use OpenCCK\Domain\Agent\ApprovalStatus;
use OpenCCK\Domain\Agent\RunStatus;
use OpenCCK\Test\Support\AgentStack;
use OpenCCK\Test\Support\FakeKeyedTool;
use OpenCCK\Test\Support\FakeTool;
use OpenCCK\Test\Support\RecordingEvents;
use OpenCCK\Test\Support\ScriptedLlm;

use function Amp\delay;

/**
 * The approval card in the chat: who may press, what a press does, silence, text and /stop while waiting.
 */
final class ApprovalServiceTest extends AsyncTestCase {
    private AgentStack $stack;
    private FakeKeyedTool $push;

    protected function setUp(): void {
        parent::setUp();
        $this->stack = new AgentStack(new ScriptedLlm([
            ScriptedLlm::calls(new ToolCall('c1', 'push', '{"remote":"origin"}')),
            ScriptedLlm::text('Готово.'),
            ScriptedLlm::calls(new ToolCall('c2', 'push', '{"remote":"origin"}')),
            ScriptedLlm::text('Снова готово.'),
        ]));
        $this->push = new FakeKeyedTool('push', 'git-push:origin');
        $this->stack->registry->register($this->push);
    }

    private function waitingRun(): void {
        $this->stack->say('отправь изменения');
        $this->stack->scheduler->awaitIdle();
        self::assertSame(RunStatus::WaitingApproval, $this->stack->runs->find(1)?->status);
    }

    private function card(): string {
        foreach ($this->stack->channel->log as $entry) {
            if ($entry[0] === 'send' && str_starts_with($entry[2], 'Подтвердите действие')) {
                return $entry[2];
            }
        }
        self::fail('No approval card');
    }

    public function testCardGoesToOwnerWithThreeButtons(): void {
        $this->waitingRun();

        $card = $this->card();
        self::assertStringContainsString('push действие', $card);
        self::assertStringContainsString('"remote": "origin"', $card);
        self::assertTrue($this->stack->channel->previewOff('push действие'), 'an address in the arguments is not opened by Telegram');
        $buttons = array_values(array_filter($this->stack->channel->buttons, static fn(array $rows) => $rows !== []));
        self::assertSame(['ap:1:a', 'ap:1:g', 'ap:1:d'], array_map(static fn($b) => $b->data, $buttons[0][0]));
        self::assertContains('Жду подтверждения владельца…', $this->stack->channel->texts('edit'));
    }

    public function testAfterOutsideDataTheCardSaysWhyAGrantWasNotUsed(): void {
        $stack = new AgentStack(new ScriptedLlm([
            ScriptedLlm::calls(new ToolCall('c1', 'web', '{}')),
            ScriptedLlm::calls(new ToolCall('c2', 'push', '{"remote":"origin"}')),
        ]));
        $stack->registry->register($this->push);
        $stack->registry->register(new \OpenCCK\Test\Support\FakeOutsideTool('web'));
        $stack->approvals->addGrant(1, 'git-push:origin');
        $this->stack = $stack;

        $this->waitingRun();

        $card = $this->card();
        self::assertStringContainsString('внешние данные', $card);
        self::assertStringContainsString('web', $card);
        self::assertSame(0, $this->push->runs);
    }

    public function testCardThatDidNotArriveIsARefusalAtOnce(): void {
        // The placeholder goes, the card does not.
        $this->stack->channel->sendResults = [\Mercurius\Messenger\SendResult::ok('1'), \Mercurius\Messenger\SendResult::failed(\Mercurius\Messenger\SendErrorKind::Transient, 'down')];

        $this->stack->say('отправь изменения');
        $this->stack->scheduler->awaitIdle();
        // The refusal resumes the run once its pause is saved.
        \Amp\delay(0.01);
        $this->stack->scheduler->awaitIdle();

        self::assertSame(RunStatus::Done, $this->stack->runs->find(1)?->status, 'no 30-minute wait for a card nobody sees');
        self::assertSame(0, $this->push->runs);
        // spec: brief/decisions.md#решения-по-доработкам-2026-11-18 — the model hears not to try again: a new card would not arrive either
        $said = implode("
", array_map(static fn($m): string => $m->content, array_filter($this->stack->llm->requests[1]->messages ?? [], static fn($m): bool => $m->role === \Mercurius\Llm\Role::Tool)));
        self::assertStringContainsString('Не повторяй', $said);
    }

    public function testActionThatCouldNotBeShownCanOnlyBeRefused(): void {
        $this->stack = new AgentStack(new ScriptedLlm([
            ScriptedLlm::calls(new ToolCall('c1', 'push', json_encode(['remote' => implode(' ', array_fill(0, 800, 'слово'))], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE))),
            ScriptedLlm::text('Готово.'),
        ]));
        $this->stack->registry->register($this->push);
        $this->stack->channel->sendFileFails = true;

        $this->stack->say('отправь изменения');
        $this->stack->scheduler->awaitIdle();

        self::assertStringContainsString('показать целиком не удалось', $this->card());
        $labels = [];
        foreach ($this->stack->channel->buttons as $rows) {
            foreach ($rows as $row) {
                foreach ($row as $button) {
                    $labels[] = $button->text;
                }
            }
        }
        self::assertContains('Отказать', $labels);
        self::assertNotContains('Разрешить', $labels, 'nobody approves what they could not read');
    }

    public function testAfterThreeRefusalsInARowTheSameActionIsRefusedWithoutACard(): void {
        $script = [];
        for ($i = 1; $i <= 4; $i++) {
            $script[] = ScriptedLlm::calls(new ToolCall('c' . $i, 'push', '{"remote":"origin"}'));
            $script[] = ScriptedLlm::text('Понял.');
        }
        $stack = new AgentStack(new ScriptedLlm($script));
        $push = new FakeKeyedTool('push', 'git-push:origin');
        $stack->registry->register($push);
        $cards = static fn(): int => count(array_filter($stack->channel->log, static fn(array $e): bool => $e[0] === 'send' && str_starts_with($e[2], 'Подтвердите действие')));

        for ($i = 1; $i <= 3; $i++) {
            $stack->say('отправь изменения');
            $stack->scheduler->awaitIdle();
            $stack->press(sprintf('ap:%d:d', $i));
            \Amp\delay(0.01);
            $stack->scheduler->awaitIdle();
        }
        self::assertSame(3, $cards());

        $stack->say('отправь изменения');
        $stack->scheduler->awaitIdle();
        \Amp\delay(0.01);
        $stack->scheduler->awaitIdle();

        self::assertSame(3, $cards(), 'the owner is not asked a fourth time');
        self::assertSame(0, $push->runs);
        $result = implode("\n", array_map(static fn(\Mercurius\Llm\Message $m): string => $m->content, $stack->llm->requests[7]->messages ?? []));
        self::assertStringContainsString('трижды', $result, 'the model is told why');
    }

    public function testApprovePressResumesTheRun(): void {
        $this->waitingRun();

        $this->stack->press('ap:1:a');
        $this->stack->scheduler->awaitIdle();

        self::assertSame(RunStatus::Done, $this->stack->runs->find(1)?->status);
        self::assertSame(1, $this->push->runs);
        self::assertSame('Разрешено.', $this->stack->channel->answers[0][1]);
        self::assertStringContainsString('Разрешено владельцем', implode("\n", $this->stack->channel->texts('edit')));
    }

    public function testTheWaitingMessageSaysTheDecisionCameAfterThePress(): void {
        $this->waitingRun();
        $edits = count($this->stack->channel->texts('edit'));

        $this->stack->press('ap:1:a');
        $this->stack->scheduler->awaitIdle();

        // spec: brief/decisions.md#решения-по-доработкам-2026-11-18 — «waiting for the owner» does not stay after the decision
        $after = array_slice($this->stack->channel->texts('edit'), $edits);
        self::assertContains('Решение владельца получено — продолжаю.', $after);
    }

    public function testAlwaysSavesTheGrantSoTheNextPushDoesNotAsk(): void {
        $this->waitingRun();
        $this->stack->press('ap:1:g');
        $this->stack->scheduler->awaitIdle();

        $this->stack->say('и ещё раз');
        $this->stack->scheduler->awaitIdle();

        self::assertTrue($this->stack->approvals->hasGrant(1, 'git-push:origin'));
        self::assertSame(2, $this->push->runs);
        self::assertSame(RunStatus::Done, $this->stack->runs->find(2)?->status);
    }

    public function testStrangerPressIsRefused(): void {
        $this->waitingRun();

        $this->stack->press('ap:1:a', '99');

        self::assertSame(['Это может только владелец.', true], array_slice($this->stack->channel->answers[0], 1));
        self::assertSame(ApprovalStatus::Pending, $this->stack->approvals->find(1)?->status);
    }

    public function testSecondPressIsAlreadyDecided(): void {
        $this->waitingRun();
        $this->stack->press('ap:1:d');
        $this->stack->press('ap:1:a');
        $this->stack->scheduler->awaitIdle();

        self::assertSame('Уже решено.', $this->stack->channel->answers[1][1]);
        self::assertSame(ApprovalStatus::Denied, $this->stack->approvals->find(1)?->status);
        self::assertSame(0, $this->push->runs);
    }

    public function testTextWhileWaitingDeniesWithThatReason(): void {
        $this->waitingRun();

        $this->stack->say('не надо, сначала тесты');
        $this->stack->scheduler->awaitIdle();

        $approval = $this->stack->approvals->find(1);
        self::assertNotNull($approval);
        self::assertSame(ApprovalStatus::Denied, $approval->status);
        self::assertSame('не надо, сначала тесты', $approval->reason);
        self::assertSame(0, $this->push->runs);
        self::assertSame(RunStatus::Done, $this->stack->runs->find(1)?->status);
    }

    public function testSilenceExpiresAsDenial(): void {
        $this->waitingRun();

        $this->stack->approvals->expireNow(1);
        $this->stack->approvalService->expireDue();
        $this->stack->scheduler->awaitIdle();

        self::assertSame(ApprovalStatus::Expired, $this->stack->approvals->find(1)?->status);
        self::assertSame(0, $this->push->runs);
        self::assertStringContainsString('Время вышло', implode("\n", $this->stack->channel->texts('edit')));
    }

    public function testStopWhileWaitingStopsTheRun(): void {
        $this->waitingRun();

        $this->stack->say('/stop');
        delay(0.05);

        self::assertSame(RunStatus::Stopped, $this->stack->runs->find(1)?->status);
        self::assertSame(ApprovalStatus::Denied, $this->stack->approvals->find(1)?->status);
    }

    public function testRunDecidedJustBeforeARestartContinuesAtStart(): void {
        $this->waitingRun();
        // The decision was saved, then the server stopped before the run went on.
        $this->stack->approvals->decide(1, ApprovalStatus::Approved, null, 1);

        $this->stack->scheduler->resumeAll();
        $this->stack->scheduler->awaitIdle();

        self::assertSame(RunStatus::Done, $this->stack->runs->find(1)?->status);
        self::assertSame(1, $this->push->runs);
    }

    public function testStoppedRunIsNotRevivedByALatePress(): void {
        $this->waitingRun();
        $this->stack->say('/stop');
        delay(0.05);

        $this->stack->scheduler->resumeRun(1);
        $this->stack->scheduler->awaitIdle();

        self::assertSame(RunStatus::Stopped, $this->stack->runs->find(1)?->status);
        self::assertSame(1, count($this->stack->llm->requests));
    }

    public function testTheApprovalStepLastsFromTheCardToTheDecision(): void {
        // spec: brief/operations.md#настройки-агента — the approval step of a trace is the owner's wait
        $events = new RecordingEvents();
        $this->stack = new AgentStack(new ScriptedLlm([ScriptedLlm::calls(new ToolCall('c1', 'push', '{"remote":"origin"}')), ScriptedLlm::text('готово')]), events: $events);
        $this->stack->registry->register(new FakeKeyedTool('push', 'git-push:origin'));
        $this->waitingRun();
        delay(0.3);

        $this->stack->press('ap:1:a');
        $this->stack->scheduler->awaitIdle();

        self::assertContains('approval:1:approved', $events->events);
        self::assertGreaterThanOrEqual(0.25, $events->approvalSeconds[0] ?? 0.0, 'not 0: the wait is measured from the card');
    }

    public function testStopWhileWaitingClosesTheRunTrace(): void {
        $events = new RecordingEvents();
        $this->stack = new AgentStack(new ScriptedLlm([ScriptedLlm::calls(new ToolCall('c1', 'push', '{"remote":"origin"}'))]), events: $events);
        $this->stack->registry->register(new FakeKeyedTool('push', 'git-push:origin'));
        $this->waitingRun();

        $this->stack->say('/stop');
        delay(0.05);

        self::assertContains('finished:1:stopped', $events->events);
    }

    public function testStopClosesTheCardAsStopped(): void {
        $this->waitingRun();
        $this->stack->say('/stop');
        delay(0.05);

        self::assertStringContainsString('Остановлено владельцем', implode("\n", $this->stack->channel->texts('edit')));
    }

    public function testWaitingRunIsLeftAloneOnRestartAndAPressContinuesIt(): void {
        $this->waitingRun();

        $this->stack->scheduler->resumeAll();
        $this->stack->scheduler->awaitIdle();
        self::assertSame(RunStatus::WaitingApproval, $this->stack->runs->find(1)?->status);
        self::assertSame([], $this->stack->runs->unfinished());

        $this->stack->press('ap:1:a');
        $this->stack->scheduler->awaitIdle();
        self::assertSame(RunStatus::Done, $this->stack->runs->find(1)?->status);
        self::assertSame(1, $this->push->runs);
    }

    public function testSecretsDoNotReachTheCardTheStoreOrTheStatus(): void {
        $stack = new AgentStack(new ScriptedLlm([
            ScriptedLlm::calls(new ToolCall('c0', 'read', '{"token":"tok-zzzz-9999"}')),
            ScriptedLlm::calls(new ToolCall('c1', 'push', '{"remote":"https://bob:hunter2@github.com/x.git","headers":{"X-Api-Key":"abcd1234"}}')),
        ]));
        $stack->registry->register(new FakeKeyedTool('push', 'git-push:origin'));
        $stack->registry->register(new FakeTool('read'));
        $stack->say('отправь');
        $stack->scheduler->awaitIdle();

        $shown = implode("\n", array_merge($stack->channel->texts('send'), $stack->channel->texts('edit')))
            . ($stack->approvals->find(1)->summary ?? '');
        self::assertStringContainsString('Выполняю read', $shown);
        self::assertStringNotContainsString('tok-zzzz-9999', $shown);
        self::assertStringNotContainsString('hunter2', $shown);
        self::assertStringNotContainsString('abcd1234', $shown);
        self::assertStringContainsString('github.com/x.git', $shown);
    }
}
