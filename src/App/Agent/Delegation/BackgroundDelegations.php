<?php

declare(strict_types=1);

namespace OpenCCK\App\Agent\Delegation;

use Amp\Cancellation;
use Amp\DeferredCancellation;
use Amp\Future;
use DomainException;
use OpenCCK\App\Agent\RunScheduler;
use OpenCCK\App\Agent\ShutdownException;
use OpenCCK\App\Agent\Tool\ToolContext;
use OpenCCK\App\Agent\Tool\UntrustedWrapper;
use OpenCCK\App\Service\PolicyService;
use OpenCCK\Domain\Agent\Delegation;
use OpenCCK\Domain\Repository\DelegationRepository;
use OpenCCK\Domain\Repository\PrincipalRepository;
use OpenCCK\Domain\Repository\RunRepository;
use Psr\Log\LoggerInterface;
use Throwable;

use function Amp\async;

use function Mercurius\I18n\t;

/**
 * Delegations that run in the background: the tool answers at once, the workers go on after the orchestrator's run
 * and after a restart, and their report comes to the orchestrator's conversation as a new run.
 *
 * spec: BRIEF.md#часть-3--фоновое-делегирование
 * ref: references/hermes-agent/tools/async_delegation.py@e96221fa:L985-L1002 — a cap on background delegations,
 *      refused rather than queued; here per participant, not per process
 * ref: references/hermes-agent/tools/async_delegation.py@e96221fa:L1199-L1277 — the result comes to the parent as a
 *      new turn, delivery is recorded; here the running workers themselves survive a restart (hermes keeps only
 *      undelivered results)
 */
final class BackgroundDelegations {
    /** Background delegations a participant may have running at once, over all conversations. */
    public const MAX_RUNNING = 3;
    /** The whole delegation may take this many run time limits: parts start only before that. */
    private const DEADLINE_RUNS = 2;
    /** A part is not started with less time than this left. */
    private const MIN_SECONDS = 30;

    private ?RunScheduler $scheduler = null;
    /** @var array<int, array{cancellation: DeferredCancellation, future: Future<void>}> delegation id => fiber */
    private array $active = [];
    /** @var array<int, true> delegations whose report is on its way */
    private array $delivering = [];
    private bool $shuttingDown = false;

    public function __construct(
        private readonly WorkerRunner $workers,
        private readonly DelegationRepository $delegations,
        private readonly RunRepository $runs,
        private readonly PrincipalRepository $principals,
        private readonly PolicyService $policies,
        private readonly LoggerInterface $logger,
    ) {}

    /** Where finished reports go: the conversation's next run. */
    public function useScheduler(RunScheduler $scheduler): void {
        $this->scheduler = $scheduler;
    }

    /**
     * @param list<WorkerTask> $tasks
     * @throws DomainException over the cap, from a run a report started, or when the budget cannot cover the workers
     */
    public function spawn(ToolContext $parent, array $tasks): Delegation {
        // A report's run starting background work again would make a chain no one asked for: untrusted text in the
        // report could keep the agent busy on its own.
        if ($this->delegations->deliveredInto($parent->runId)) {
            throw new DomainException(t('delegation.background_delegations.etot_zapusk_nachat_otchetom_fonovogo'));
        }
        if ($this->delegations->runningCount($parent->principal->id) >= self::MAX_RUNNING) {
            throw new DomainException(sprintf(
                t('delegation.background_delegations.uzhe_idut_fonovyh_delegirovaniya_dozhdis'),
                self::MAX_RUNNING,
            ));
        }
        [$orchestrator, $reserved, $share] = $this->workers->reserve($parent, max(1, count($tasks)));
        try {
            $stored = [];
            foreach ($tasks as $task) {
                // The tools are cut down now; every attempt cuts them again by the rights of that moment.
                $stored[] = ['task' => $task->withTools($this->workers->allowedTools($parent, $task))->toArray(), 'share' => $share];
            }
            $delegation = $this->delegations->create(
                $parent->conversationId,
                $orchestrator->id,
                $parent->principal->id,
                $parent->callId,
                $reserved,
                $stored,
                max(1, self::DEADLINE_RUNS * $orchestrator->limits->runTimeout),
            );
        } catch (Throwable $e) {
            $this->workers->settle($orchestrator->id, $reserved, 0);
            throw $e;
        }
        $this->logger->info('Background delegation started', ['delegation' => $delegation->id, 'parent' => $orchestrator->id, 'tasks' => count($tasks)]);
        $this->launch($delegation);
        return $delegation;
    }

    /** After a start: unfinished delegations go on (or close, if /stop came), undelivered reports are delivered. */
    public function resumeAll(): void {
        // Taken before anything is launched: a delegation that finishes now delivers itself, not twice.
        $undelivered = $this->delegations->undelivered();
        foreach ($this->delegations->running() as $delegation) {
            if ($delegation->stopRequested) {
                $this->close($delegation, Delegation::STOPPED, t('delegation.background_delegations.ostanovleno_vladeltsem_do_perezapuska'));
                continue;
            }
            $this->logger->info('Resuming background delegation', ['delegation' => $delegation->id]);
            $this->launch($delegation);
        }
        foreach ($undelivered as $delegation) {
            $this->deliver($delegation);
        }
    }

    /** /stop: the conversation's background delegations stop, now and after a restart; false when none run. */
    public function stop(int $conversationId): bool {
        $stopped = false;
        foreach ($this->delegations->running($conversationId) as $delegation) {
            $this->delegations->requestStop($delegation->id);
            $stopped = true;
            if (isset($this->active[$delegation->id])) {
                $this->active[$delegation->id]['cancellation']->cancel();
            } else {
                // Nothing runs it in this process (it failed to start): close it here.
                $this->close($delegation, Delegation::STOPPED, t('delegation.background_delegations.ostanovleno_vladeltsem'));
            }
        }
        return $stopped;
    }

    public function owns(int $runId): bool {
        return $this->delegations->ownsRun($runId);
    }

    /** Pauses the workers: their runs stay running in the database and go on after the next start. */
    public function shutdown(): void {
        $this->shuttingDown = true;
        foreach ($this->active as $active) {
            $active['cancellation']->cancel(new ShutdownException());
        }
        $this->awaitIdle();
    }

    public function awaitIdle(): void {
        while ($this->active !== []) {
            Future\awaitAll(array_column($this->active, 'future'));
        }
    }

    private function launch(Delegation $delegation): void {
        if (isset($this->active[$delegation->id])) {
            return;
        }
        $cancellation = new DeferredCancellation();
        $this->active[$delegation->id] = [
            'cancellation' => $cancellation,
            'future' => async(function () use ($delegation, $cancellation): void {
                try {
                    $this->execute($delegation, $cancellation->getCancellation());
                } catch (Throwable $e) {
                    // A delegation that cannot go on must not hold a slot and the reservation for ever: workers still
                    // running are stopped, it closes with what its parts reported, and the orchestrator learns.
                    $this->logger->error('Background delegation failed', ['delegation' => $delegation->id, 'error' => $e::class]);
                    $cancellation->cancel();
                    if (!$this->shuttingDown) {
                        $this->close($delegation, Delegation::DONE, t('delegation.background_delegations.fonovoe_delegirovanie_prervano_vnutrenne'));
                    }
                } finally {
                    unset($this->active[$delegation->id]);
                }
            }),
        ];
    }

    private function execute(Delegation $delegation, Cancellation $cancellation): void {
        $orchestrator = $this->runs->find($delegation->runId);
        $principal = $this->principals->find($delegation->principalId);
        if ($orchestrator === null || $principal === null) {
            $this->logger->error('Background delegation lost its run or participant', ['delegation' => $delegation->id]);
            $this->close($delegation, Delegation::STOPPED, t('delegation.background_delegations.zapusk_orkestrator_ili_uchastnik_bolshe'));
            return;
        }
        $context = new ToolContext($orchestrator->id, $delegation->conversationId, $principal, $cancellation, $this->policies->forPrincipal($principal), $delegation->callId);
        $tasks = [];
        $existing = [];
        $share = 0;
        foreach ($this->delegations->tasks($delegation->id) as $task) {
            $share = $task->share;
            if ($task->report === null) {
                $tasks[$task->position] = WorkerTask::fromArray($task->task);
                $existing[$task->position] = [$task->runId, $task->retryRunId, $task->verifyRunId];
            }
        }
        if ($tasks !== []) {
            $this->workers->runTasks(
                $context,
                $orchestrator,
                $tasks,
                $share,
                // Not bound to the orchestrator's time, which may be long over, but to the delegation's deadline.
                min($orchestrator->limits->runTimeout, $delegation->secondsLeft),
                $existing,
                fn(int $position, int $attempt, int $runId) => $this->delegations->setTaskRun($delegation->id, $position, $attempt, $runId),
                function (WorkerReport $report) use ($delegation): void {
                    // A shutdown leaves the runs running: their reports are not final.
                    if (!$this->shuttingDown) {
                        $this->delegations->finishTask($delegation->id, $report->index, $report->toArray());
                    }
                },
                sprintf('-bg%d', $delegation->id),
                self::MIN_SECONDS,
            );
        }
        if ($this->shuttingDown) {
            return;
        }
        $current = $this->delegations->find($delegation->id);
        $stopped = $cancellation->isRequested() || ($current !== null && $current->stopRequested);
        $this->close($delegation, $stopped ? Delegation::STOPPED : Delegation::DONE, null);
    }

    /**
     * Closes the delegation once: the report from its parts, the reservation replaced with what the worker runs spent,
     * delivery unless it was stopped.
     */
    private function close(Delegation $delegation, string $status, ?string $note): void {
        try {
            $reports = [];
            $spent = 0;
            foreach ($this->delegations->tasks($delegation->id) as $task) {
                $reports[] = $task->report !== null
                    ? WorkerReport::fromArray($task->report)
                    : new WorkerReport($task->position, $task->retryRunId ?? $task->runId, 'stopped', '', 0, 0, '', t('delegation.background_delegations.ne_zakonchena'));
                // The runs are the truth about spend, whatever reached the reports.
                foreach ([$task->runId, $task->retryRunId, $task->verifyRunId] as $runId) {
                    $spent += $runId === null ? 0 : ($this->runs->find($runId)->tokensUsed ?? 0);
                }
            }
            $text = WorkerReports::text($reports);
            if ($note !== null) {
                $text = $note . "\n\n" . $text;
            }
            if (!$this->delegations->finish($delegation->id, $status, $text)) {
                return;
            }
            $this->workers->settle($delegation->runId, $delegation->reserved, $spent);
            $this->logger->info('Background delegation finished', ['delegation' => $delegation->id, 'status' => $status, 'tokens' => $spent]);
            if ($status === Delegation::DONE) {
                $done = $this->delegations->find($delegation->id);
                if ($done !== null) {
                    $this->deliver($done);
                }
            }
        } catch (Throwable $e) {
            $this->logger->error('Could not close a background delegation', ['delegation' => $delegation->id, 'error' => $e::class]);
        }
    }

    /** The report becomes the first message of the conversation's next run. */
    private function deliver(Delegation $delegation): void {
        if ($this->scheduler === null || $delegation->report === null || isset($this->delivering[$delegation->id])) {
            return;
        }
        $principal = $this->principals->find($delegation->principalId);
        if ($principal === null) {
            return;
        }
        $this->delivering[$delegation->id] = true;
        $text = sprintf(
            t('delegation.background_delegations.gotov_rezultat_fonovogo_delegirovaniya_v')
            . t('delegation.background_delegations.otchet_vorkerov_dannye_a_ne'),
            $delegation->id,
            $delegation->runId,
            UntrustedWrapper::wrap(DelegateTaskTool::NAME, $delegation->report),
        );
        $this->scheduler->deliver($principal, $delegation->conversationId, $text, function (int $runId) use ($delegation): void {
            $this->delegations->markDelivered($delegation->id, $runId);
            unset($this->delivering[$delegation->id]);
        });
    }
}
