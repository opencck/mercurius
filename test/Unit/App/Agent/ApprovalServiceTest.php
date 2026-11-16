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
        $buttons = array_values(array_filter($this->stack->channel->buttons, static fn(array $rows) => $rows !== []));
        self::assertSame(['ap:1:a', 'ap:1:g', 'ap:1:d'], array_map(static fn($b) => $b->data, $buttons[0][0]));
        self::assertContains('Жду подтверждения владельца…', $this->stack->channel->texts('edit'));
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
