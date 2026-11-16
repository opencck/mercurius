<?php

declare(strict_types=1);

namespace OpenCCK\Test\Support;

use Amp\PHPUnit\AsyncTestCase;
use OpenCCK\Domain\Agent\Delegation;
use OpenCCK\Domain\Agent\RunLimits;
use OpenCCK\Domain\Repository\DelegationRepository;
use OpenCCK\Domain\Repository\RunRepository;

/**
 * Behaviour every delegation store must have: the in-memory fake locally, PostgreSQL on the server.
 */
abstract class DelegationStoreContract extends AsyncTestCase {
    protected DelegationRepository $delegations;
    protected RunRepository $runs;
    protected int $principalId;
    protected int $conversationId;

    private function orchestrator(): int {
        $run = $this->runs->start($this->conversationId, $this->principalId, 'm/x', new RunLimits(30, 100_000, 900, 120));
        self::assertNotNull($run);
        return $run->id;
    }

    private function create(int $runId): Delegation {
        return $this->delegations->create($this->conversationId, $runId, $this->principalId, 'call-1', 20_000, [
            ['task' => ['goal' => 'раз', 'tools' => ['read']], 'share' => 10_000],
            ['task' => ['goal' => 'два', 'profile' => ['code' => 7]], 'share' => 10_000],
        ], 600);
    }

    public function testCreatedDelegationRunsWithItsParts(): void {
        $delegation = $this->create($this->orchestrator());

        self::assertSame(Delegation::RUNNING, $delegation->status);
        self::assertSame(20_000, $delegation->reserved);
        self::assertSame(1, $this->delegations->runningCount($this->principalId));
        self::assertSame(0, $this->delegations->runningCount($this->principalId + 1000));
        self::assertGreaterThan(590, $delegation->secondsLeft);
        self::assertLessThanOrEqual(600, $delegation->secondsLeft);
        self::assertFalse($delegation->stopRequested);
        $tasks = $this->delegations->tasks($delegation->id);
        self::assertCount(2, $tasks);
        self::assertSame(1, $tasks[0]->position);
        self::assertSame(['goal' => 'два', 'profile' => ['code' => 7]], $tasks[1]->task);
        self::assertNull($tasks[0]->runId);
        self::assertNull($tasks[0]->report);
    }

    public function testPartRunsAndReportsAreKept(): void {
        $orchestrator = $this->orchestrator();
        $delegation = $this->create($orchestrator);
        $this->runs->finish($orchestrator, \OpenCCK\Domain\Agent\RunStatus::Done, \OpenCCK\Domain\Agent\StopReason::Answered);
        $worker = $this->runs->start($this->conversationId, $this->principalId, 'm/w', new RunLimits(15, 10_000, 900, 120), $orchestrator);
        self::assertNotNull($worker);

        $this->delegations->setTaskRun($delegation->id, 1, 1, $worker->id);
        $this->delegations->finishTask($delegation->id, 1, ['status' => 'done', 'summary' => 'итог']);

        $task = $this->delegations->tasks($delegation->id)[0];
        self::assertSame($worker->id, $task->runId);
        self::assertNull($task->retryRunId);
        self::assertSame(['status' => 'done', 'summary' => 'итог'], $task->report);
        self::assertTrue($this->delegations->ownsRun($worker->id));
        self::assertFalse($this->delegations->ownsRun($orchestrator));

        // The cascade's retry is a worker of the delegation too: the scheduler must leave it to the delegation.
        $this->runs->finish($worker->id, \OpenCCK\Domain\Agent\RunStatus::Failed, \OpenCCK\Domain\Agent\StopReason::ModelFailed);
        $retry = $this->runs->start($this->conversationId, $this->principalId, 'm/s', new RunLimits(15, 10_000, 900, 120), $orchestrator);
        self::assertNotNull($retry);
        $this->delegations->setTaskRun($delegation->id, 1, 2, $retry->id);
        self::assertSame($retry->id, $this->delegations->tasks($delegation->id)[0]->retryRunId);
        self::assertSame($worker->id, $this->delegations->tasks($delegation->id)[0]->runId);
        self::assertTrue($this->delegations->ownsRun($retry->id));

        $this->runs->finish($retry->id, \OpenCCK\Domain\Agent\RunStatus::Done, \OpenCCK\Domain\Agent\StopReason::Answered);
        $verifier = $this->runs->start($this->conversationId, $this->principalId, 'm/v', new RunLimits(15, 10_000, 900, 120), $orchestrator);
        self::assertNotNull($verifier);
        $this->delegations->setTaskRun($delegation->id, 1, 3, $verifier->id);
        self::assertSame($verifier->id, $this->delegations->tasks($delegation->id)[0]->verifyRunId);
        self::assertSame($retry->id, $this->delegations->tasks($delegation->id)[0]->retryRunId);
        self::assertTrue($this->delegations->ownsRun($verifier->id));
    }

    public function testStopRequestIsKept(): void {
        $delegation = $this->create($this->orchestrator());

        $this->delegations->requestStop($delegation->id);

        self::assertTrue($this->delegations->find($delegation->id)?->stopRequested);
    }

    public function testStoppedOnesAreNotDelivered(): void {
        $delegation = $this->create($this->orchestrator());

        $this->delegations->finish($delegation->id, Delegation::STOPPED, 'частично');

        self::assertSame([], $this->delegations->undelivered());
    }

    public function testFinishIsOnceAndDeliveryIsTracked(): void {
        $delegation = $this->create($this->orchestrator());

        self::assertTrue($this->delegations->finish($delegation->id, Delegation::DONE, 'отчёт'));
        self::assertFalse($this->delegations->finish($delegation->id, Delegation::STOPPED, null), 'already closed');

        self::assertSame(0, $this->delegations->runningCount($this->conversationId));
        $undelivered = $this->delegations->undelivered();
        self::assertSame([$delegation->id], array_map(static fn(Delegation $d): int => $d->id, $undelivered));
        self::assertSame('отчёт', $undelivered[0]->report);

        $orchestrator = $this->delegations->find($delegation->id)->runId ?? 0;
        $this->runs->finish($orchestrator, \OpenCCK\Domain\Agent\RunStatus::Done, \OpenCCK\Domain\Agent\StopReason::Answered);
        $next = $this->runs->start($this->conversationId, $this->principalId, 'm/x', new RunLimits(30, 100_000, 900, 120));
        self::assertNotNull($next);
        $this->delegations->markDelivered($delegation->id, $next->id);

        self::assertSame([], $this->delegations->undelivered());
        self::assertTrue($this->delegations->find($delegation->id)?->delivered);
        self::assertTrue($this->delegations->deliveredInto($next->id));
        self::assertFalse($this->delegations->deliveredInto($orchestrator));
    }

    public function testRunningListIsByConversation(): void {
        $first = $this->create($this->orchestrator());

        self::assertSame([$first->id], array_map(static fn(Delegation $d): int => $d->id, $this->delegations->running($this->conversationId)));
        self::assertSame([], $this->delegations->running($this->conversationId + 1000));
        self::assertContains($first->id, array_map(static fn(Delegation $d): int => $d->id, $this->delegations->running()));
    }
}
