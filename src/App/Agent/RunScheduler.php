<?php

declare(strict_types=1);

namespace OpenCCK\App\Agent;

use Amp\DeferredCancellation;
use Amp\Future;
use Closure;
use DateTimeImmutable;
use Mercurius\Messenger\Channel;
use Mercurius\Messenger\IncomingMessage;
use Mercurius\Messenger\OutgoingMessage;
use OpenCCK\App\Agent\Delegation\BackgroundDelegations;
use OpenCCK\App\Agent\Model\ModelRouter;
use OpenCCK\App\Agent\Tool\Guard\UntrustedWrapper;
use OpenCCK\App\Agent\Trace\AgentEvents;
use OpenCCK\App\Agent\Trace\NullEvents;
use OpenCCK\App\Service\PolicyService;
use OpenCCK\App\Service\SystemPrompt;
use OpenCCK\App\Skill\SkillLibrary;
use OpenCCK\Domain\Agent\ConversationMeta;
use OpenCCK\Domain\Agent\DangerClass;
use OpenCCK\Domain\Agent\ModelRef;
use OpenCCK\Domain\Agent\ParticipantPolicy;
use OpenCCK\Domain\Agent\Run;
use OpenCCK\Domain\Agent\RunStatus;
use OpenCCK\Domain\Agent\StopReason;
use OpenCCK\Domain\Agent\ToolCallStatus;
use OpenCCK\Domain\Entity\Permission;
use OpenCCK\Domain\Entity\Member;
use OpenCCK\Domain\Repository\ConversationRepository;
use OpenCCK\Domain\Repository\MemberRepository;
use OpenCCK\Domain\Repository\RunRepository;
use OpenCCK\Domain\Repository\TranscriptRepository;
use Mercurius\Memory\App\MemoryContext;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

use function Amp\async;
use function Amp\delay;

use function Mercurius\I18n\t;
use OpenCCK\App\Agent\Tool\Guard\SecretMasker;

/**
 * At most one active run per conversation, each in its own fiber outside the chat queue: the message handler only
 * hands the message over and returns, so /stop and later messages are never stuck behind a run. A message that
 * arrives during a run goes into it as a steering note; messages that come within a short window are merged.
 *
 * spec: brief/agent-loop.md#запуск-и-цикл — one active run per conversation, steering
 * spec: brief/agent-loop.md#устойчивость — resume after restart without repeating side effects
 * ref: references/hermes-agent/gateway/platforms/base.py@e96221fa:L3690-L3697 (_text_debounce_delay) — merge window
 *      0.35 s after the last message, at most 1 s after the first
 * ref: references/hermes-agent/gateway/run_busy.py@e96221fa:L200-L215 — a steering note left after the turn becomes
 *      the next turn instead of vanishing
 */
final class RunScheduler {
    /** @var array<int, bool> conversation => its last message expects no answer */
    private array $silence = [];
    public const RESUMED = 'agent.run_scheduler.resumed';
    public const INTERRUPTED = 'agent.run_scheduler.interrupted';
    public const CRASHED = 'agent.run_scheduler.crashed';
    public const NOT_STARTED = 'agent.run_scheduler.not_started';

    /** @var array<int, true> conversations whose run was told to stop and is still finishing */
    private array $stopping = [];

    /** @var array<int, array{cancellation: DeferredCancellation, future: Future<void>}> conversation id => running fiber */
    private array $active = [];
    /** @var array<int, array{texts: list<string>, own: list<string>, first: float, last: float, message: IncomingMessage, principal: Member, quiet: bool, outside: list<string>, future: Future<void>}> */
    private array $pending = [];
    /** @var array<int, ChannelRunObserver> the observer of each run that paused for the owner: its message is updated once decided */
    private array $waiting = [];
    /** seconds the recall of memory may take before a run starts without it */
    private const RECALL_SECONDS = 3.0;
    /** @var array<int, list<string>> outside data that came to a conversation between runs: the next run is marked */
    private array $tainted = [];
    private ?ApprovalService $approvals = null;
    /** @var (Closure(string, string, string): list<string>)|null who spoke in a group chat or topic in the last day */
    private ?Closure $speakers = null;
    /** @var array<int, ParticipantPolicy> by run: a ceiling after someone with fewer rights joined */
    private array $caps = [];
    /** @var list<JobRuns> */
    private array $jobRuns = [];
    /** @var array<int, string|null> the forum topic of each conversation, from its last message */
    private array $threads = [];
    private ?BackgroundDelegations $background = null;
    private ?MemoryContext $memory = null;
    private ?ProjectContext $projects = null;
    private ?\OpenCCK\App\Improve\PromptNotes $notes = null;
    private string $instance = '';
    private ?SkillLibrary $skills = null;
    /** @var array<int, string> conversation => the index of skills its prompt or last note had */
    private array $skillSnapshots = [];
    private ?ConversationAudience $audience = null;
    /** @var array<int, Future<void>> deliveries waiting for the conversation's run to end */
    private array $deliveries = [];
    private int $nextDelivery = 0;
    private bool $shuttingDown = false;
    /** Seconds between looks at a conversation whose run waits for the owner, when a delivery waits for it. */
    private const DELIVERY_POLL = 2.0;

    public function __construct(
        private readonly AgentLoop $loop,
        private readonly RunRepository $runs,
        private readonly TranscriptRepository $transcript,
        private readonly ConversationRepository $conversations,
        private readonly MemberRepository $principals,
        private readonly PolicyService $policies,
        private readonly Channel $channel,
        private readonly string $model,
        private readonly LoggerInterface $logger = new NullLogger(),
        private readonly float $debounce = 0.35,
        private readonly float $debounceCap = 1.0,
        /** talk nobody addressed to the agent (a free group) waits for this much silence… */
        private readonly float $quietDebounce = 8.0,
        /** …but not longer than this after its first message */
        private readonly float $quietCap = 30.0,
        private readonly float $editInterval = 1.5,
        private readonly ?ModelRouter $router = null,
        private readonly AgentEvents $events = new NullEvents(),
        /** @var list<string>|(\Closure(): list<string>) providers that answer now; empty — not checked */
        private readonly array|\Closure $providers = [],
        /** remembers which run wrote each message, so a reply names its run */
        private readonly ?\OpenCCK\Domain\Repository\RunMessageRepository $runMessages = null,
        /** obligations to deliver final answers */
        private readonly ?\OpenCCK\Domain\Repository\DeliveryRepository $answers = null,
    ) {}

    public function useApprovals(ApprovalService $approvals): void {
        $this->approvals = $approvals;
    }

    /**
     * Who spoke in a group chat or topic in the last day: a run there gets no more rights than the weakest of them,
     * and someone who joins during a run narrows it before the model reads their words.
     *
     * spec: brief/channels.md#права-запуска-в-группе — in a group the rights of the weakest active participant
     * @param Closure(string, string, string): list<string> $speakers channel, chat, topic => user ids of the channel
     */
    public function useGroupSpeakers(Closure $speakers): void {
        $this->speakers = $speakers;
        $this->loop->useCap(fn(int $runId): ?ParticipantPolicy => $this->caps[$runId] ?? null);
    }

    /**
     * The policy of a run in this conversation: the participant's own, and in a group no more than that of anyone who
     * spoke there in the last day. Someone the channel knows but who is not an active participant, and a stranger,
     * count as a visitor.
     */
    /**
     * Whether a group's words may be someone else's: any group, unless the owner writes and nobody but the owner spoke
     * there in the last day — the owner's own words are not outside data, in a group as in the private chat.
     *
     * spec: brief/agent-loop.md#запуск-начатый-внешними-данными
     */
    private function othersSpoke(\OpenCCK\Domain\Agent\ConversationMeta $meta, Member $principal): bool {
        if (!str_starts_with($meta->chatId, '-')) {
            return false;
        }
        if (!$principal->isOwner() || $this->speakers === null) {
            return true;
        }
        foreach (($this->speakers)($meta->channel, $meta->chatId, $meta->threadId) as $userId) {
            if ($this->principals->findByIdentity($meta->channel, $userId)?->id !== $principal->id) {
                return true;
            }
        }
        return false;
    }

    private ?\OpenCCK\App\Service\ProjectPolicies $projectPolicies = null;

    /** spec: brief/participants.md#права-проекта — a run in a project's chat, topic or job works with the project's rights */
    public function useProjectPolicies(\OpenCCK\App\Service\ProjectPolicies $policies): void {
        $this->projectPolicies = $policies;
    }

    /** The policy a participant's run in a conversation gets now: for a background delegation after a restart. */
    public function policyFor(int $conversationId, Member $principal): ParticipantPolicy {
        return $this->policyIn($conversationId, $principal);
    }

    /** The project of the conversation's chat or topic, when project rights apply here. */
    private function projectAt(?ConversationMeta $meta): ?\OpenCCK\Domain\Project\Project {
        return $meta === null || $this->projectPolicies === null ? null : $this->projects?->active($meta->channel, $meta->chatId, $meta->threadId);
    }

    private function policyIn(int $conversationId, Member $principal): ParticipantPolicy {
        $policy = $this->policies->forPrincipal($principal);
        $meta = $this->conversations->meta($conversationId);
        // spec: brief/participants.md#права-проекта — in a project the project's rights, whoever of its members writes
        $project = $this->projectAt($meta);
        $inProject = $project === null ? null : $this->projectPolicies?->forRun($project, $principal);
        $members = null;
        if ($inProject !== null) {
            $policy = $inProject;
            $members = $project;
        }
        // spec: brief/skills-mcp.md#процессы-и-реестр-инструментов-по-контекстам — a chat of a project gets the project's
        //       connections, a private chat the member's own; a group without a project none
        $policy = $policy->withMcpContext(match (true) {
            $members !== null => 'project:' . $members->id,
            $meta !== null && !str_starts_with($meta->chatId, '-') => 'member:' . $principal->id,
            default => null,
        }, open: true);
        $speakers = $this->speakers;
        if ($speakers === null || $meta === null) {
            return $policy;
        }
        $channel = $meta->channel;
        $chatId = $meta->chatId;
        if (!str_starts_with($chatId, '-')) {
            return $policy;
        }
        $visitor = null;
        foreach ($speakers($channel, $chatId, $meta->threadId) as $userId) {
            $speaker = $this->principals->findByIdentity($channel, $userId);
            if ($speaker !== null && $speaker->id === $principal->id) {
                continue;
            }
            // a member of the project does not narrow its rights: the owner of the project set them; anyone else does
            if ($members !== null && $speaker !== null && $speaker->status === \OpenCCK\Domain\Entity\MemberStatus::Active && $this->projectPolicies?->forRun($members, $speaker) !== null) {
                continue;
            }
            if ($speaker === null || $speaker->status !== \OpenCCK\Domain\Entity\MemberStatus::Active) {
                $visitor ??= $this->policies->visitor();
                $policy = $policy->narrowedBy($visitor, keepApproval: $principal->isOwner());
                continue;
            }
            $policy = $policy->narrowedBy($this->policies->forPrincipal($speaker), keepApproval: $principal->isOwner());
        }
        return $policy;
    }

    /** What someone who joins a run brings: the project's rights for its member, their own otherwise. */
    private function joinerCap(int $conversationId, Member $principal): ParticipantPolicy {
        $project = $this->projectAt($this->conversations->meta($conversationId));
        $inProject = $project === null ? null : $this->projectPolicies?->forRun($project, $principal);
        return $inProject ?? $this->policies->forPrincipal($principal);
    }

    /** Runs of scheduled jobs go on through the job scheduler. */
    public function useJobRuns(JobRuns $jobs): void {
        $this->jobRuns[] = $jobs;
    }

    private function jobRunsOf(Run $run): ?JobRuns {
        foreach ($this->jobRuns as $owner) {
            if ($owner->owns($run)) {
                return $owner;
            }
        }
        return null;
    }

    public function useBackground(BackgroundDelegations $background): void {
        $this->background = $background;
    }

    /**
     * A message from the agent itself (a background delegation's report) becomes the first message of a new run, once
     * the conversation is free — not a steering note: a note can be dropped by /stop, and in a group the running run
     * may be another participant's. The callback gets the run only after the text is saved in it; a restart before
     * that leaves the message undelivered, to be delivered again.
     *
     * spec: brief/orchestration.md#часть-3--фоновое-делегирование — delivery
     *
     * @param \Closure(int): void $onDelivered gets the run the text started
     */
    /**
     * Outside data came to a conversation outside a run (the answer of a neighbour): the run now going, or the next
     * one, is marked — no saved grant applies in it.
     *
     * spec: brief/agent-loop.md#запуск-начатый-внешними-данными
     */
    public function taint(int $conversationId, string $source): void {
        $run = $this->runs->active($conversationId);
        if ($run !== null) {
            $this->runs->markOutside($run->id, $source);
            return;
        }
        $this->tainted[$conversationId] = array_values(array_unique([...($this->tainted[$conversationId] ?? []), $source]));
    }

    /** @param string|null $outside where the text came from when it is not the agent's own (a worker's report) */
    public function deliver(Member $principal, int $conversationId, string $text, \Closure $onDelivered, ?string $outside = null): void {
        $id = $this->nextDelivery++;
        $this->deliveries[$id] = async(function () use ($id, $principal, $conversationId, $text, $onDelivered, $outside): void {
            try {
                while (!$this->shuttingDown) {
                    if (isset($this->active[$conversationId])) {
                        $this->active[$conversationId]['future']->await();
                        continue;
                    }
                    if (isset($this->pending[$conversationId])) {
                        $this->pending[$conversationId]['future']->await();
                        continue;
                    }
                    if ($this->runs->active($conversationId) !== null) {
                        // A run waiting for the owner's decision: its fiber is gone, so look again a bit later.
                        delay(self::DELIVERY_POLL);
                        continue;
                    }
                    // spec: brief/orchestration.md#часть-3--фоновое-делегирование — /new is a clean start: a report that finished
                    //       before it does not bring the old conversation back
                    if (!$this->conversations->isOpen($conversationId)) {
                        $this->logger->info('Delivery dropped: the conversation was closed', ['conversation' => $conversationId]);
                        return;
                    }
                    // A delivered report is the agent's own text, often quoting the web: no recall on it.
                    $runId = $this->start($principal, $conversationId, $text, null, mayJoin: false, recall: false, outside: $outside === null ? [] : [$outside]);
                    if ($runId !== null) {
                        $onDelivered($runId);
                        return;
                    }
                }
            } catch (Throwable $e) {
                $this->logger->error('Could not deliver a message', ['conversation' => $conversationId, 'error' => $e::class]);
            } finally {
                unset($this->deliveries[$id]);
            }
        });
    }

    /** Hands a message over and returns at once. */
    /** @param bool $silenceAllowed nobody asked the agent (a free group): it may answer [SILENT] */
    /**
     * @param string|null $outside where the data from outside in the message came from (files, someone's answer): the
     *        run that takes it is marked; null — the message is the participant's own words
     * @param string|null $own the participant's own words in the message, which facts of memory are looked up by; null —
     *        the whole text when nothing came from outside, none when it did (someone's answer)
     */
    public function submit(Member $principal, IncomingMessage $message, int $conversationId, bool $silenceAllowed = false, ?string $outside = null, ?string $own = null): void {
        // spec: brief/channels.md#форум-темы--проекты — the answer goes to the forum topic the message came from
        $this->threads[$conversationId] = $message->threadId;
        // the batch may stay silent only if nobody in it asked the agent
        $this->silence[$conversationId] = isset($this->pending[$conversationId]) ? ($this->silence[$conversationId] ?? false) && $silenceAllowed : $silenceAllowed;
        $text = trim($message->text);
        // spec: brief/memory.md#как-сделано-области-и-факты — facts are looked up by the participant's own words: what a
        //      file said or someone else's answer brings facts of words nobody of the conversation wrote
        $own = trim($own ?? ($outside === null ? $text : ''));
        $this->audience()->remember($conversationId, $message->isPrivateChat);
        // A run being stopped takes no more steering: the message waits and starts the next run.
        $run = isset($this->stopping[$conversationId]) ? null : $this->runs->active($conversationId);
        // spec: brief/agent-loop.md#подтверждения-и-запреты — a text from the owner while the run waits is a denial with that reason
        if ($run !== null && $run->status === RunStatus::WaitingApproval && $principal->isOwner() && $this->approvals?->denyPending($run->id, $text, t('agent.run_scheduler.otkazano_vladelets_otvetil_tekstom'), $principal->id) === true) {
            $this->resumeRun($run->id);
            return;
        }
        if ($run !== null) {
            // spec: brief/channels.md#права-запуска-в-группе — someone who joins a group's run narrows it before the
            //      model reads their words (the ceiling is read before the next model call, which delivers the text)
            if ($this->speakers !== null && $principal->id !== $run->principalId) {
                $starter = $this->principals->find($run->principalId);
                if ($starter !== null) {
                    $joined = $this->policyIn($conversationId, $starter)->capTo($this->joinerCap($conversationId, $principal));
                    $this->caps[$run->id] = isset($this->caps[$run->id]) ? $this->caps[$run->id]->capTo($joined) : $joined;
                }
            }
            if ($outside !== null) {
                // spec: brief/internet.md#безопасность — files are data from outside: no saved grant in this run from now on
                $this->runs->markOutside($run->id, $outside);
            }
            $this->transcript->inbox($run->id, $text, $message->messageId);
            return;
        }
        $now = microtime(true);
        if (isset($this->pending[$conversationId])) {
            $this->pending[$conversationId]['texts'][] = $text;
            if ($own !== '') {
                $this->pending[$conversationId]['own'][] = $own;
            }
            $this->pending[$conversationId]['last'] = $now;
            $this->pending[$conversationId]['outside'] = $outside === null ? $this->pending[$conversationId]['outside'] : [...$this->pending[$conversationId]['outside'], $outside];
            // someone asked: the batch no longer waits for the group to fall silent
            $this->pending[$conversationId]['quiet'] = $this->pending[$conversationId]['quiet'] && $silenceAllowed;
            return;
        }
        $this->pending[$conversationId] = [
            'texts' => [$text],
            'own' => $own === '' ? [] : [$own],
            'first' => $now,
            'last' => $now,
            'message' => $message,
            'principal' => $principal,
            'quiet' => $silenceAllowed,
            'outside' => $outside === null ? [] : [$outside],
            'future' => async($this->startAfterDebounce(...), $conversationId),
        ];
    }

    /** Stops the conversation's run and its background delegations; false when nothing runs. */
    public function stop(int $conversationId): bool {
        $background = $this->background?->stop($conversationId) ?? false;
        return $this->stopRun($conversationId) || $background;
    }

    private function stopRun(int $conversationId): bool {
        if (isset($this->pending[$conversationId])) {
            $this->pending[$conversationId]['future']->ignore();
            unset($this->pending[$conversationId]);
            return true;
        }
        if (isset($this->active[$conversationId])) {
            $this->stopping[$conversationId] = true;
            $this->active[$conversationId]['cancellation']->cancel();
            return true;
        }
        $run = $this->runs->active($conversationId);
        return $run !== null && $run->status === RunStatus::WaitingApproval && $this->stopWaiting($run);
    }

    /** Continues a run whose approval was decided; does nothing if the run no longer waits. */
    public function resumeRun(int $runId): void {
        $run = $this->runs->find($runId);
        if ($run === null || $run->status !== RunStatus::WaitingApproval) {
            return;
        }
        $owner = $this->jobRunsOf($run);
        if ($owner !== null) {
            $owner->continueRun($run, true);
            return;
        }
        // The fiber that paused the run may still be finishing its last step.
        while (isset($this->active[$run->conversationId])) {
            $this->active[$run->conversationId]['future']->await();
        }
        $meta = $this->conversations->meta($run->conversationId);
        $principal = $this->principals->find($run->principalId);
        if ($meta === null || $principal === null) {
            $this->logger->error('Run cannot continue: conversation or participant is gone', ['run' => $run->id]);
            return;
        }
        // Only one of a press, a text, /stop and a restart takes the run out of waiting.
        $claimed = $this->runs->transition($run->id, RunStatus::WaitingApproval, RunStatus::Running);
        if ($claimed === null) {
            return;
        }
        $paused = $this->waiting[$run->id] ?? null;
        unset($this->waiting[$run->id]);
        $paused?->settled(\Mercurius\I18n\t('agent.run_scheduler.decided'));
        $observer = new ChannelRunObserver($this->channel, $meta->chatId, null, $this->editInterval, logger: $this->logger, threadId: $this->thread($meta, $run->conversationId), messages: $this->runMessages, silenceAllowed: $this->silence[$meta->id] ?? false, deliveries: $this->answers);
        $observer->start();
        $this->launch($claimed, $principal, $observer);
    }

    /** /stop while the run waits: the approval is denied, open calls are closed, the run is stopped. */
    private function stopWaiting(Run $run): bool {
        if ($this->runs->transition($run->id, RunStatus::WaitingApproval, RunStatus::Stopped) === null) {
            // A decision took the run first; it is running now and stops the usual way.
            if (isset($this->active[$run->conversationId])) {
                $this->stopping[$run->conversationId] = true;
                $this->active[$run->conversationId]['cancellation']->cancel();
                return true;
            }
            return false;
        }
        $this->approvals?->denyPending($run->id, t('agent.run_scheduler.ostanovleno_vladeltsem'), t('agent.run_scheduler.ostanovleno_vladeltsem'));
        foreach ($this->transcript->pendingCalls($run->id) as $call) {
            $this->transcript->appendToolResult($run->conversationId, $run->id, $call->callId, ToolCallStatus::Interrupted, t('agent.run_scheduler.ostanovleno_vladeltsem'));
        }
        $this->transcript->appendAssistant($run->conversationId, $run->id, \Mercurius\I18n\t(AgentLoop::STOPPED_NOTE), []);
        $this->runs->finish($run->id, StopReason::Stopped->status(), StopReason::Stopped);
        $this->events->runFinished($run->id, 'stopped', StopReason::Stopped->value, ($this->runs->find($run->id) ?? $run)->activeMs);
        $this->transcript->consumeInbox($run->id);
        $meta = $this->conversations->meta($run->conversationId);
        if ($meta !== null) {
            $this->channel->send(new OutgoingMessage($meta->chatId, t('agent.run_scheduler.ostanovleno')));
        }
        $this->logger->info('Waiting run stopped', ['run' => $run->id]);
        return true;
    }

    /** Continues runs a restart interrupted. Write and irreversible calls that were running are not repeated. */
    public function resumeAll(): void {
        foreach ($this->runs->unfinished() as $run) {
            try {
                if ($run->parentRunId !== null && $this->background?->owns($run->id) === true) {
                    // spec: brief/orchestration.md#часть-3--фоновое-делегирование — a background worker goes on with its delegation
                    continue;
                }
                if ($run->parentRunId !== null) {
                    // spec: brief/orchestration.md#часть-1--синхронное-делегирование — a worker belonged to a delegation call that the
                    // restart interrupted; its orchestrator learns that from the call, the worker does not go on alone.
                    $this->runs->finish($run->id, StopReason::InternalError->status(), StopReason::InternalError);
                    $this->events->runFinished($run->id, 'failed', 'interrupted by a restart', $run->activeMs);
                    continue;
                }
                $owner = $this->jobRunsOf($run);
                if ($owner !== null) {
                    $owner->continueRun($run, false);
                    continue;
                }
                $this->resume($run);
            } catch (Throwable $e) {
                $this->logger->error('Could not resume a run', ['run' => $run->id, 'error' => $e::class]);
            }
        }
        // A decision saved just before the server stopped: the run waits for nothing any more.
        foreach ($this->runs->waitingApproval() as $run) {
            try {
                if ($this->approvals !== null && !$this->approvals->isPending($run->id)) {
                    $this->resumeRun($run->id);
                }
            } catch (Throwable $e) {
                $this->logger->error('Could not resume a decided run', ['run' => $run->id, 'error' => $e::class]);
            }
        }
    }

    /** Cancels every run with a shutdown reason: they stay running in the database and resume on the next start. */
    public function shutdown(): void {
        $this->shuttingDown = true;
        foreach ($this->pending as $pending) {
            $pending['future']->ignore();
        }
        $this->pending = [];
        foreach ($this->active as $active) {
            $active['cancellation']->cancel(new ShutdownException());
        }
        $this->awaitIdle();
    }

    /** Waits until no run is pending or running. */
    public function awaitIdle(): void {
        while ($this->pending !== [] || $this->active !== [] || $this->deliveries !== []) {
            $futures = array_merge(array_column($this->pending, 'future'), array_column($this->active, 'future'), array_values($this->deliveries));
            Future\awaitAll($futures);
        }
    }

    private function startAfterDebounce(int $conversationId): void {
        while (isset($this->pending[$conversationId])) {
            $pending = $this->pending[$conversationId];
            // ref: references/hermes-agent/gateway/platforms/webhook_coalesce.py@e96221fa:L1-L9 — a burst waits for a quiet
            //      window, bounded past its first event, and goes as one run; here the window is for a free group's talk
            $wait = $pending['quiet']
                ? min($pending['last'] + $this->quietDebounce, $pending['first'] + $this->quietCap) - microtime(true)
                : min($pending['last'] + $this->debounce, $pending['first'] + $this->debounceCap) - microtime(true);
            if ($wait <= 0) {
                break;
            }
            delay($wait);
        }
        // A run that is still finishing (e.g. after /stop) goes first; the waiting text starts the next one.
        while (isset($this->active[$conversationId])) {
            $this->active[$conversationId]['future']->await();
        }
        $pending = $this->pending[$conversationId] ?? null;
        unset($this->pending[$conversationId]);
        if ($pending === null) {
            return;
        }
        try {
            $this->start($pending['principal'], $conversationId, implode("\n\n", $pending['texts']), $pending['message']->messageId, outside: $pending['outside'], own: implode("\n\n", $pending['own']));
        } catch (Throwable $e) {
            $this->logger->error('Could not start a run', ['conversation' => $conversationId, 'error' => $e::class]);
            $this->channel->send(new OutgoingMessage($pending['message']->chatId, \Mercurius\I18n\t(self::NOT_STARTED), $pending['message']->messageId));
        }
    }

    /** The index of skills goes into the prompt of new conversations; a change reaches open ones as a note. */
    public function useSkills(SkillLibrary $skills): void {
        $this->skills = $skills;
    }

    /** The active project of a chat goes into the prompt of its new conversations. */
    /** spec: brief/self-improvement.md#самоулучшение — the active notes the owner accepted come into new prompts */
    /** spec: brief/network.md#подключения-агентов — the agent knows its own address and how a neighbour connects to it */
    /** @var (Closure(string): bool)|null whether a model is in one of the owner's text lists */
    private ?Closure $listed = null;

    /**
     * The owner's lists of text models: a conversation whose model left them is routed anew at its next run.
     *
     * @param Closure(string): bool $listed
     */
    public function useModelLists(Closure $listed): void {
        $this->listed = $listed;
    }

    public function useInstance(string $about): void {
        $this->instance = $about;
    }

    public function useNotes(\OpenCCK\App\Improve\PromptNotes $notes): void {
        $this->notes = $notes;
    }

    public function useProjects(ProjectContext $projects): void {
        $this->projects = $projects;
    }

    /**
     * The context of the orchestrator's conversation a worker asked to have it gets: the same blocks the orchestrator's
     * own prompt has — the active project, and the participant's core memory only where the conversation is private.
     * Its report comes back into the same conversation, so it learns nothing the orchestrator could not tell it.
     *
     * spec: brief/orchestration.md#права-воркера-задаёт-оркестратор
     *
     * @param list<string> $of 'project', 'participant'
     */
    public function workerContext(\OpenCCK\App\Agent\Tool\ToolContext $parent, array $of): string {
        $meta = $this->conversations->meta($parent->conversationId);
        if ($meta === null) {
            return '';
        }
        $parts = [];
        if (in_array('project', $of, true)) {
            $project = $this->project($parent->principal, $meta->channel, $meta->chatId, $meta->threadId);
            if ($project !== '') {
                $parts[] = UntrustedWrapper::wrap('project', $project);
            }
        }
        if (in_array('participant', $of, true)) {
            $core = $this->core($parent->principal, $parent->conversationId);
            if ($core !== '') {
                $parts[] = UntrustedWrapper::wrap('participant', $core);
            }
        }
        return implode("\n\n", $parts);
    }

    /**
     * The forum topic answers go to: the conversation's own topic (kept in the base, so a run resumed after a restart
     * answers in it), else the thread of the last message (a reply chain in an ordinary group).
     */
    private function thread(ConversationMeta $meta, int $conversationId): ?string {
        return $meta->threadId !== '' ? $meta->threadId : ($this->threads[$conversationId] ?? null);
    }

    private function project(Member $principal, string $channel, string $chatId, string $threadId = ''): string {
        if ($this->projects === null) {
            return '';
        }
        try {
            return $this->projects->block($principal, $channel, $chatId, $threadId);
        } catch (Throwable $e) {
            $this->logger->warning('Project context is not available', ['error' => $e::class]);
            return '';
        }
    }

    /** @var list<Closure(Run, Member): void> told when a run ends, in the background */
    private array $afterRun = [];

    /**
     * Something to do after a run ended (the background proposal of facts): it does not hold the conversation.
     *
     * @param Closure(Run, Member): void $then
     */
    public function useAfterRun(Closure $then): void {
        $this->afterRun[] = $then;
    }

    private ?ConversationPlan $plan = null;

    /** spec: brief/agent-loop.md#список-дел-беседы — what of the conversation's plan is left goes into every run */
    public function usePlan(ConversationPlan $plan): void {
        $this->plan = $plan;
    }

    /** The active items of the conversation's plan after the text of the turn. */
    private function withPlan(int $conversationId, string $text): string {
        $plan = $this->plan?->active($conversationId);
        return $plan === null ? $text : $text . "\n\n" . $plan;
    }

    /** @param ConversationAudience|null $audience shared with the memory tools */
    public function useMemory(MemoryContext $memory, ?ConversationAudience $audience = null): void {
        $this->memory = $memory;
        $this->audience = $audience ?? $this->audience;
    }

    private function audience(): ConversationAudience {
        return $this->audience ??= new ConversationAudience($this->conversations);
    }

    /** spec: brief/participants.md#контекст-по-месту-и-аудитории — layer 1 is the owner's, so only the owner's private chat gets it */
    /** The active notes the owner accepted ('' when none); the prompt text kept with the conversation records them. */
    private function notes(): string {
        try {
            return $this->notes?->active() ?? '';
        } catch (Throwable $e) {
            $this->logger->warning('Prompt notes were not read', ['error' => $e::class]);
            return '';
        }
    }

    private function core(Member $principal, int $conversationId): string {
        $place = $this->placeMemory($principal, $conversationId)[1];
        if (!$this->audience()->isPrivate($conversationId)) {
            return $place;
        }
        return implode("\n\n", array_filter([$this->coreOf($principal), $place], static fn(string $p): bool => $p !== ''));
    }

    /**
     * Layer 1 of a participant's memory and their MEMORY.md, for a place the caller found private.
     *
     * spec: brief/participants.md#несколько-пользователей — a participant gets their own layer 1, never the owner's
     */
    public function coreOf(Member $principal): string {
        if ($this->memory === null && $this->memoryFile === null) {
            return '';
        }
        $parts = [];
        if ($this->memory !== null) {
            try {
                // spec: brief/participants.md#несколько-пользователей — a participant gets their own layer 1, never the owner's
                $parts[] = $this->memory->core($principal->isOwner() ? \Mercurius\Memory\App\Audience::OWNER : \Mercurius\Memory\App\Audience::principal($principal->id));
            } catch (Throwable $e) {
                $this->logger->warning('Memory core is not available', ['error' => $e::class]);
            }
        }
        $parts[] = $this->memoryFile($principal)[1];
        return implode("\n\n", array_filter($parts, static fn(string $p): bool => $p !== ''));
    }

    /**
     * The section «Правила» of the participant's MEMORY.md as a block of the system prompt, for their private chat.
     *
     * spec: brief/memory.md#правила-и-заметки-memorymd — the rules are instructions of the person, apart from the reference data
     */
    private function rules(Member $principal, int $conversationId): string {
        $own = $this->audience()->isPrivate($conversationId) ? $this->memoryFile($principal)[0] : '';
        return implode("\n\n", array_filter([$own, $this->placeMemory($principal, $conversationId)[0]], static fn(string $p): bool => $p !== ''));
    }

    /** @var (Closure(Member, string, string, string, bool): string)|null the MEMORY.md of the place of a conversation */
    private ?Closure $placeFile = null;

    /**
     * The MEMORY.md of the place a conversation is in — a project's or a group's, channel's, topic's — for everyone who
     * writes there: its rules as instructions of the place, its notes as reference data.
     *
     * spec: brief/memory.md#файл-памяти-места
     *
     * @param Closure(Member, string, string, string, bool): string $read the person, channel, chat, topic and whether
     *        the chat is private; the text of the file, '' — none
     */
    public function usePlaceMemory(Closure $read): void {
        $this->placeFile = $read;
    }

    /** @return array{string, string} the rules block and the notes block of the place's file */
    private function placeMemory(Member $principal, int $conversationId): array {
        $meta = $this->placeFile === null ? null : $this->conversations->meta($conversationId);
        if ($this->placeFile === null || $meta === null) {
            return ['', ''];
        }
        try {
            $file = MemoryFile::parse(($this->placeFile)($principal, $meta->channel, $meta->chatId, $meta->threadId, $this->audience()->isPrivate($conversationId)));
        } catch (Throwable $e) {
            $this->logger->warning('MEMORY.md of the place is not available', ['error' => $e::class]);
            return ['', ''];
        }
        return self::memoryBlocks($file, $this->memoryFileLimit, 'place');
    }

    /** @var (Closure(Member): string)|null the participant's MEMORY.md; null — no such file */
    private ?Closure $memoryFile = null;
    private int $memoryFileLimit = 4000;

    /**
     * The participant's MEMORY.md in the prompt of their private chat: the rules as a block of their own, the notes next
     * to layer 1; within its limit the rules whole and the notes in what is left, with how full the file is and a word
     * to compress it from 85 %.
     *
     * spec: brief/memory.md#правила-и-заметки-memorymd — two sections, the limit, how full
     *
     * @param Closure(Member): string $read the file, '' when there is none
     * @param positive-int $limit characters
     */
    public function useMemoryFile(Closure $read, int $limit): void {
        $this->memoryFile = $read;
        $this->memoryFileLimit = $limit;
    }

    /** @return array{string, string} the block of the rules, the block of the notes; '' — none */
    private function memoryFile(Member $principal): array {
        if ($this->memoryFile === null) {
            return ['', ''];
        }
        try {
            $file = MemoryFile::parse(($this->memoryFile)($principal));
        } catch (Throwable $e) {
            $this->logger->warning('MEMORY.md is not available', ['error' => $e::class]);
            return ['', ''];
        }
        return self::memoryBlocks($file, $this->memoryFileLimit, 'person');
    }

    /**
     * The two blocks of a MEMORY.md within its limit: the rules as instructions (with secrets masked), the notes as
     * reference data with how full the file is; a file past 85 % of the limit asks to be compressed, one past it says
     * what was cut. No file, or an empty one, gives nothing.
     *
     * ref: references/hermes-agent/tools/memory_tool_store.py@e96221fa:L675-L682 (_render_block) — the head of the block
     *      says how full it is («67% — 1,474/2,200 chars»); hermes refuses an entry past the limit
     *      (L403-L418), here the file is the agent's to compress, as the owner decided
     *
     * @param 'person'|'place' $whose
     * @return array{string, string}
     */
    public static function memoryBlocks(MemoryFile $file, int $limit, string $whose): array {
        if ($file->empty()) {
            return ['', ''];
        }
        $limit = max(1, $limit);
        [$rules, $notes, $cut] = $file->within($limit);
        $total = $file->length();
        $fill = sprintf(t('memory.file.fill'), (int) round(100.0 * (float) $total / (float) $limit), $total, $limit);
        $word = $cut ? sprintf(t('memory.file.over'), $total, $limit) : ($total >= (int) ((float) $limit * 0.85) ? t('memory.file.near') : '');
        $rulesBlock = $rules === '' ? '' : t($whose === 'place' ? 'memory.file.rules_place' : 'memory.file.rules_person') . "\n" . SecretMasker::mask($rules);
        $notesBlock = t($whose === 'place' ? 'memory.file.head_place' : 'memory.file.head') . ' ' . $fill
            . ($notes === '' ? '' : "\n" . $notes) . ($word === '' ? '' : "\n\n" . $word);
        return [$rulesBlock, $notesBlock];
    }

    /** The facts that answer the text go after it as reference data; memory failing never stops the run. */
    /**
     * spec: brief/skills-mcp.md#как-сделано-каталог-замок-и-проверка-скиллов — a change of the catalog in the middle of a conversation is a one-off note on the
     *       next message, the prompt stays as it was
     */
    private function withSkillNote(Member $principal, ConversationMeta $meta, int $conversationId, string $text): string {
        if ($this->skills === null || !$principal->role->may(Permission::Skills)) {
            return $text;
        }
        try {
            $before = $this->skillSnapshots[$conversationId] ?? null;
            $now = $this->skills->index($principal, $this->projects?->active($meta->channel, $meta->chatId, $meta->threadId));
            $this->skillSnapshots[$conversationId] = $now;
        } catch (Throwable) {
            return $text;
        }
        if (count($this->skillSnapshots) > 500) {
            $this->skillSnapshots = array_slice($this->skillSnapshots, -250, null, true);
        }
        return $before === null || $before === $now ? $text : $text . "\n\n" . t('skill.library.changed') . "\n" . ($now === '' ? '—' : $now);
    }

    /** @param string $own the participant's own words: facts are looked up by them, they come after the whole text */
    private function withFacts(Member $principal, ConversationMeta $meta, string $text, string $own): string {
        if ($this->memory === null) {
            return $text;
        }
        $memory = $this->memory;
        $scopes = $this->audience()->scopes($principal, $meta->id);
        // spec: brief/memory.md#факты — the names of the owners and of the one who writes bring no facts by themselves
        $selfNames = array_values(array_filter(array_map(static fn(Member $m): ?string => $m->displayName, [$principal, ...$this->principals->owners()]), static fn(?string $n): bool => $n !== null && trim($n) !== ''));
        try {
            // spec: brief/memory.md#запись-сведения-о-людях-и-забывание — a slow recall does not hold the answer: after the time
            //       the run starts without it
            /** @var string $facts */
            $facts = async(static fn(): string => $memory->facts($own, $scopes, $meta->id, $selfNames))->await(new \Amp\TimeoutCancellation(self::RECALL_SECONDS));
        } catch (\Amp\CancelledException) {
            $this->logger->warning('Memory recall took too long; the run starts without it', ['conversation' => $meta->id, 'seconds' => self::RECALL_SECONDS]);
            return $text;
        } catch (Throwable $e) {
            $this->logger->warning('Memory recall failed', ['conversation' => $meta->id, 'error' => $e::class]);
            return $text;
        }
        return $facts === '' ? $text : $text . "\n\n" . UntrustedWrapper::wrap('memory', t('agent.run_scheduler.iz_pamyati_spravochnye_dannye_ne') . $facts);
    }

    /**
     * @param list<string> $outside where the outside data of the text came from: the run starts marked
     * @param string|null $own the words facts are looked up by; null — the whole text, '' — none
     * @return int|null the run the text started; null when it joined another run or nothing started
     */
    private function start(Member $principal, int $conversationId, string $text, ?string $messageId, bool $mayJoin = true, bool $recall = true, array $outside = [], ?string $own = null): ?int {
        $meta = $this->conversations->meta($conversationId);
        if ($meta === null) {
            $this->logger->error('Conversation not found', ['conversation' => $conversationId]);
            return null;
        }
        $model = $meta->model ?? $this->model;
        // spec: brief/models.md#провайдеры-моделей-openrouter-и-openai — the conversation was opened with a provider whose key
        // is gone: its runs go to the default model instead of failing every time
        // the providers are read at every run: a provider of an MCP comes and goes while the application runs
        $providers = $this->providers instanceof \Closure ? ($this->providers)() : $this->providers;
        if ($meta->systemPrompt !== null && $providers !== [] && !in_array(ModelRef::parse($model)->provider, $providers, true)) {
            $this->logger->warning('Conversation model has no provider key; the default one is used', ['conversation' => $conversationId, 'model' => $model]);
            $model = $this->model;
        }
        // spec: brief/models.md#списки-моделей-по-категориям — a model in no list is never called: when the owner changed the
        //       lists and the model of an open conversation left them, its next run is routed anew
        if ($meta->systemPrompt !== null && $this->router !== null && $this->listed !== null && !($this->listed)($model)) {
            $next = (string) $this->router->choose($conversationId, $text);
            if ($next !== $model) {
                $this->conversations->switchModel($conversationId, $next);
                $this->logger->notice('Conversation model left the lists; the conversation moves', ['conversation' => $conversationId, 'from' => $model, 'to' => $next]);
                $model = $next;
            }
        }
        if ($meta->systemPrompt === null) {
            // spec: brief/agent-loop.md#модели — the model is chosen when the conversation opens and kept while the model of its role
            //       stays; a change of the role moves the open conversation (RoleConversations), its next run reads it here
            $model = $this->router !== null ? (string) $this->router->choose($conversationId, $text) : $this->model;
            $skills = '';
            if ($this->skills !== null && $principal->role->may(Permission::Skills)) {
                try {
                    $skills = $this->skills->index($principal, $this->projects?->active($meta->channel, $meta->chatId, $meta->threadId));
                    $this->skillSnapshots[$conversationId] = $skills;
                } catch (Throwable $e) {
                    $this->logger->warning('Skills index is not available', ['error' => $e::class]);
                }
            }
            $notes = $this->notes();
            $this->conversations->setMeta($conversationId, PromptBuilder::system($principal, new DateTimeImmutable(), $this->core($principal, $conversationId), $this->project($principal, $meta->channel, $meta->chatId, $meta->threadId), $skills, $meta->channel, $notes, $this->instance, $this->rules($principal, $conversationId)), $model, SystemPrompt::VERSION);
            $model = $this->conversations->meta($conversationId)->model ?? $model;
        }
        $policy = $this->policyIn($conversationId, $principal);
        // spec: brief/agent-loop.md#разрешения-навсегда — in a group other people's words are in the context: saved grants do not apply
        // spec: brief/agent-loop.md#запуск-начатый-внешними-данными — the mark is set at the start, before the model reads anything
        $sources = array_values(array_unique([...($this->othersSpoke($meta, $principal) ? ['group chat'] : []), ...$outside, ...($this->tainted[$conversationId] ?? [])]));
        unset($this->tainted[$conversationId]);
        $run = $this->runs->start($conversationId, $principal->id, $model, $policy->run, null, $sources === [] ? null : implode(', ', $sources));
        if ($run === null) {
            // Lost a race with another start: the text joins the run that won.
            $active = $this->runs->active($conversationId);
            if ($active !== null && $mayJoin) {
                $this->transcript->inbox($active->id, $text, $messageId);
            }
            return null;
        }
        try {
            $this->transcript->appendUser($conversationId, $run->id, $this->withPlan($conversationId, $this->withSkillNote($principal, $meta, $conversationId, $recall && ($own ?? $text) !== '' ? $this->withFacts($principal, $meta, $text, $own ?? $text) : $text)), $messageId);
            $observer = new ChannelRunObserver($this->channel, $meta->chatId, $messageId, $this->editInterval, logger: $this->logger, threadId: $this->thread($meta, $conversationId), messages: $this->runMessages, silenceAllowed: $this->silence[$meta->id] ?? false, deliveries: $this->answers);
            $observer->start();
        } catch (Throwable $e) {
            // The run exists but will never run: free the conversation.
            $this->runs->finish($run->id, StopReason::InternalError->status(), StopReason::InternalError);
            throw $e;
        }
        $this->launch($run, $principal, $observer);
        return $run->id;
    }

    private function resume(Run $run): void {
        $meta = $this->conversations->meta($run->conversationId);
        $principal = $this->principals->find($run->principalId);
        if ($meta === null || $principal === null) {
            $this->logger->error('Run cannot be resumed: conversation or participant is gone', ['run' => $run->id]);
            return;
        }
        foreach ($this->transcript->pendingCalls($run->id) as $call) {
            if ($call->status === ToolCallStatus::Running && $call->danger !== DangerClass::Read) {
                $this->transcript->appendToolResult($run->conversationId, $run->id, $call->callId, ToolCallStatus::Interrupted, \Mercurius\I18n\t(self::INTERRUPTED));
            }
        }
        $this->logger->info('Resuming run after restart', ['run' => $run->id]);
        $observer = new ChannelRunObserver($this->channel, $meta->chatId, null, $this->editInterval, logger: $this->logger, threadId: $this->thread($meta, $run->conversationId), messages: $this->runMessages, silenceAllowed: $this->silence[$meta->id] ?? false, deliveries: $this->answers);
        $observer->start(\Mercurius\I18n\t(self::RESUMED));
        $this->launch($run, $principal, $observer);
    }

    private function launch(Run $run, Member $principal, ChannelRunObserver $observer): void {
        $conversationId = $run->conversationId;
        $cancellation = new DeferredCancellation();
        $policy = $this->policyIn($conversationId, $principal);
        $this->active[$conversationId] = [
            'cancellation' => $cancellation,
            'future' => async(function () use ($run, $principal, $policy, $observer, $cancellation, $conversationId): void {
                try {
                    $final = $this->loop->run($run, $policy, $principal, $observer, $cancellation->getCancellation());
                } catch (Throwable $e) {
                    $this->logger->error('Run crashed', ['run' => $run->id, 'error' => $e::class]);
                    $this->fail($run, $observer);
                    return;
                } finally {
                    unset($this->caps[$run->id]);
                    // Only our own entry: a newer run of the conversation may have started meanwhile.
                    if (($this->active[$conversationId]['cancellation'] ?? null) === $cancellation) {
                        unset($this->active[$conversationId], $this->stopping[$conversationId]);
                    }
                }
                if ($final->status === RunStatus::WaitingApproval) {
                    $this->waiting[$run->id] = $observer;
                }
                if (!$final->status->isActive()) {
                    $this->carryOver($final, $principal);
                    foreach ($this->afterRun as $then) {
                        async(function () use ($then, $final, $principal): void {
                            try {
                                $then($final, $principal);
                            } catch (Throwable $e) {
                                $this->logger->error('After-run work failed', ['run' => $final->id, 'error' => $e::class]);
                            }
                        })->ignore();
                    }
                }
            }),
        ];
    }

    /** A run that crashed is closed as failed, so the conversation is free and the owner knows. */
    private function fail(Run $run, ChannelRunObserver $observer): void {
        try {
            $this->approvals?->denyPending($run->id, t('agent.run_scheduler.zadacha_prervana_iz_za_vnutrenney'), t('agent.run_scheduler.zadacha_prervana_iz_za_vnutrenney'));
        } catch (Throwable $e) {
            $this->logger->error('Could not close the approval of a crashed run', ['run' => $run->id, 'error' => $e::class]);
        }
        try {
            foreach ($this->transcript->pendingCalls($run->id) as $call) {
                $this->transcript->appendToolResult($run->conversationId, $run->id, $call->callId, ToolCallStatus::Failed, t('agent.run_scheduler.ne_vypolneno_vnutrennyaya_oshibka'));
            }
        } catch (Throwable $e) {
            $this->logger->error('Could not close calls of a crashed run', ['run' => $run->id, 'error' => $e::class]);
        }
        try {
            // Closes the task in the history, so the next run does not take it up again.
            $this->transcript->appendAssistant($run->conversationId, $run->id, \Mercurius\I18n\t(self::CRASHED), []);
        } catch (Throwable $e) {
            $this->logger->error('Could not close the history of a crashed run', ['run' => $run->id, 'error' => $e::class]);
        }
        try {
            $this->runs->finish($run->id, StopReason::InternalError->status(), StopReason::InternalError);
            $this->events->runFinished($run->id, 'failed', StopReason::InternalError->value, ($this->runs->find($run->id) ?? $run)->activeMs);
            $observer->finished($this->runs->find($run->id) ?? $run, \Mercurius\I18n\t(self::CRASHED), StopReason::InternalError);
        } catch (Throwable $e) {
            $this->logger->error('Could not close a crashed run', ['run' => $run->id, 'error' => $e::class]);
        }
    }

    /** Steering notes that came after the run's last iteration start the next run instead of being lost. */
    private function carryOver(Run $run, Member $principal): void {
        try {
            $left = $this->transcript->consumeInbox($run->id);
            if ($left === [] || $run->stopReason === StopReason::Stopped) {
                return;
            }
            $this->start($principal, $run->conversationId, implode("\n\n", $left), null);
        } catch (Throwable $e) {
            $this->logger->error('Could not start a run from leftover messages', ['run' => $run->id, 'error' => $e::class]);
        }
    }
}
