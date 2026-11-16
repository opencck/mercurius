<?php

declare(strict_types=1);

namespace OpenCCK\Test\Support;

use Amp\PHPUnit\AsyncTestCase;
use Mercurius\Llm\Role;
use OpenCCK\Domain\Agent\DangerClass;
use OpenCCK\Domain\Agent\Run;
use OpenCCK\Domain\Agent\RunLimits;
use OpenCCK\Domain\Agent\RunStatus;
use OpenCCK\Domain\Agent\StopReason;
use OpenCCK\Domain\Agent\StoredToolCall;
use OpenCCK\Domain\Agent\ToolCallStatus;
use OpenCCK\Domain\Repository\RunRepository;
use OpenCCK\Domain\Repository\TranscriptRepository;

use function Amp\async;
use function Amp\Future\await;

/**
 * Behaviour every run store must have. The in-memory fakes run it locally, PostgreSQL — on the server,
 * so the fakes cannot drift from the database.
 */
abstract class RunStoreContract extends AsyncTestCase {
    protected RunRepository $runs;
    protected TranscriptRepository $transcript;
    protected int $principalId;
    protected int $conversationId;

    private static function limits(int $budget = 100): RunLimits {
        return new RunLimits(30, $budget, 900, 120);
    }

    private function startRun(int $budget = 100): int {
        $run = $this->runs->start($this->conversationId, $this->principalId, 'm/x', self::limits($budget));
        self::assertNotNull($run);
        return $run->id;
    }

    private function fetchRun(int $id): Run {
        $run = $this->runs->find($id);
        self::assertNotNull($run);
        return $run;
    }

    private static function call(string $id, int $position, DangerClass $danger = DangerClass::Read, ToolCallStatus $status = ToolCallStatus::Pending): StoredToolCall {
        return new StoredToolCall($id, $position, 'fake', ['q' => $id], '{"q":"' . $id . '"}', $danger, $status);
    }

    public function testStartRefusesSecondActiveRun(): void {
        $this->startRun();

        self::assertNull($this->runs->start($this->conversationId, $this->principalId, 'm/x', self::limits()));
    }

    public function testFinishedRunFreesTheConversation(): void {
        $id = $this->startRun();
        $this->runs->finish($id, RunStatus::Done, StopReason::Answered);

        self::assertNull($this->runs->active($this->conversationId));
        $run = $this->fetchRun($id);
        self::assertSame(RunStatus::Done, $run->status);
        self::assertSame(StopReason::Answered, $run->stopReason);
        self::assertNotNull($this->runs->start($this->conversationId, $this->principalId, 'm/x', self::limits()));
    }

    public function testReserveRefusesBeyondBudget(): void {
        $id = $this->startRun(100);

        self::assertTrue($this->runs->reserve($id, 60));
        self::assertFalse($this->runs->reserve($id, 50));
        self::assertSame(60, $this->fetchRun($id)->tokensReserved);
    }

    public function testConcurrentReservesNeverOverspend(): void {
        $id = $this->startRun(100);
        $results = await(array_map(fn(int $i) => async(fn(): bool => $this->runs->reserve($id, 20)), range(1, 10)));

        self::assertSame(5, count(array_filter($results)));
    }

    public function testSettleReplacesReservation(): void {
        $id = $this->startRun(100);
        $this->runs->reserve($id, 60);
        $this->runs->settle($id, 60, 25, 20);

        $run = $this->fetchRun($id);
        self::assertSame(0, $run->tokensReserved);
        self::assertSame(25, $run->tokensUsed);
        self::assertSame(1, $run->turns);
        self::assertSame(20, $run->promptAnchor);
        self::assertTrue($this->runs->reserve($id, 75));
    }

    public function testActiveTimeAndWarningAreKept(): void {
        $id = $this->startRun();
        $this->runs->addActiveTime($id, 1500);
        $this->runs->addActiveTime($id, 500);
        $this->runs->markWarned($id);

        self::assertSame(2000, $this->fetchRun($id)->activeMs);
        self::assertTrue($this->fetchRun($id)->warned);
    }

    public function testUnfinishedListsRunningRuns(): void {
        $id = $this->startRun();

        self::assertSame([$id], array_map(static fn($r) => $r->id, $this->runs->unfinished()));
    }

    public function testAssistantAndCallsAreSavedTogether(): void {
        $id = $this->startRun();
        $this->transcript->appendUser($this->conversationId, $id, 'задача');
        $this->transcript->appendAssistant($this->conversationId, $id, 'думаю', [self::call('c1', 0), self::call('c2', 1, DangerClass::Write)]);

        $messages = $this->transcript->runMessages($id);
        self::assertCount(2, $messages);
        self::assertSame(Role::Assistant, $messages[1]->role);
        self::assertSame(['c1', 'c2'], array_map(static fn($c) => $c->id, $messages[1]->toolCalls));
        self::assertSame('{"q":"c2"}', $messages[1]->toolCalls[1]->argumentsJson);
        self::assertSame(['c1', 'c2'], array_map(static fn($c) => $c->callId, $this->transcript->pendingCalls($id)));
        self::assertSame(DangerClass::Write, $this->transcript->pendingCalls($id)[1]->danger);
        self::assertSame(['c1', 'c2'], $this->transcript->callIds($id));
    }

    public function testToolResultClosesTheCall(): void {
        $id = $this->startRun();
        $this->transcript->appendAssistant($this->conversationId, $id, '', [self::call('c1', 0), self::call('c2', 1)]);
        $this->transcript->startCall($id, 'c1');
        self::assertSame(ToolCallStatus::Running, $this->transcript->pendingCalls($id)[0]->status);

        $this->transcript->appendToolResult($this->conversationId, $id, 'c1', ToolCallStatus::Done, 'результат');

        self::assertSame(['c2'], array_map(static fn($c) => $c->callId, $this->transcript->pendingCalls($id)));
        $last = $this->transcript->runMessages($id)[1];
        self::assertSame(Role::Tool, $last->role);
        self::assertSame('c1', $last->toolCallId);
        self::assertSame('результат', $last->content);
    }

    public function testHistorySkipsToolRoundsAndCurrentRun(): void {
        $this->transcript->appendUser($this->conversationId, null, 'старое');
        $first = $this->startRun();
        $this->transcript->appendUser($this->conversationId, $first, 'вопрос 1');
        $this->transcript->appendAssistant($this->conversationId, $first, '', [self::call('c1', 0)]);
        $this->transcript->appendToolResult($this->conversationId, $first, 'c1', ToolCallStatus::Done, 'r');
        $this->transcript->appendAssistant($this->conversationId, $first, 'ответ 1', []);
        $this->runs->finish($first, RunStatus::Done, StopReason::Answered);
        $second = $this->startRun();
        $this->transcript->appendUser($this->conversationId, $second, 'вопрос 2');

        $history = $this->transcript->history($this->conversationId, $second, 10);
        self::assertSame(['старое', 'вопрос 1', 'ответ 1'], array_map(static fn($m) => $m->content, $history));
        self::assertSame(['ответ 1'], array_map(static fn($m) => $m->content, $this->transcript->history($this->conversationId, $second, 1)));
    }

    public function testConsumeInboxReturnsEachMessageOnce(): void {
        $id = $this->startRun();
        $this->transcript->inbox($id, 'раз');
        $this->transcript->inbox($id, 'два');

        self::assertSame(['раз', 'два'], $this->transcript->consumeInbox($id));
        self::assertSame([], $this->transcript->consumeInbox($id));
    }
}
