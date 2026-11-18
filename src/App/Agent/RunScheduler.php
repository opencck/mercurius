<?php

declare(strict_types=1);

namespace OpenCCK\App\Agent;

use Amp\DeferredCancellation;
use Amp\Future;
use DateTimeImmutable;
use Mercurius\Messenger\Channel;
use Mercurius\Messenger\IncomingMessage;
use Mercurius\Messenger\OutgoingMessage;
use OpenCCK\App\Agent\Delegation\BackgroundDelegations;
use OpenCCK\App\Agent\Model\ModelRouter;
use OpenCCK\App\Agent\Trace\AgentEvents;
use OpenCCK\App\Agent\Trace\NullEvents;
use OpenCCK\App\Agent\Tool\UntrustedWrapper;
use OpenCCK\App\Service\PolicyService;
use OpenCCK\App\Service\SystemPrompt;
use OpenCCK\Domain\Agent\ConversationMeta;
use OpenCCK\Domain\Agent\DangerClass;
use OpenCCK\Domain\Agent\ModelRef;
use OpenCCK\Domain\Agent\Run;
use OpenCCK\Domain\Agent\RunStatus;
use OpenCCK\Domain\Agent\StopReason;
use OpenCCK\Domain\Agent\ToolCallStatus;
use OpenCCK\Domain\Entity\Principal;
use OpenCCK\Domain\Entity\Role;
use OpenCCK\App\Skill\SkillLibrary;
use OpenCCK\Domain\Repository\ConversationRepository;
use OpenCCK\Domain\Repository\PrincipalRepository;
use OpenCCK\Domain\Repository\RunRepository;
use OpenCCK\Domain\Repository\TranscriptRepository;
use OpenCCK\Memory\App\MemoryContext;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Closure;
use Throwable;

use function Amp\async;
use function Amp\delay;

use function Mercurius\I18n\t;

/**
 * At most one active run per conversation, each in its own fiber outside the chat queue: the message handler only
 * hands the message over and returns, so /stop and later messages are never stuck behind a run. A message that
 * arrives during a run goes into it as a steering note; messages that come within a short window are merged.
 *
 * spec: BRIEF.md#запуск-и-цикл — one active run per conversation, steering
 * spec: BRIEF.md#устойчивость — resume after restart without repeating side effects
 * ref: references/hermes-agent/gateway/platforms/base.py@e96221f:L3690-L3697 (_text_debounce_delay) — merge window
 *      0.35 s after the last message, at most 1 s after the first
 * ref: references/hermes-agent/gateway/run_busy.py@e96221f:L200-L215 — a steering note left after the turn becomes
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
    /** @var array<int, array{texts: list<string>, first: float, last: float, message: IncomingMessage, principal: Principal, quiet: bool, outside: bool, future: Future<void>}> */
    private array $pending = [];
    private ?ApprovalService $approvals = null;
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
        private readonly PrincipalRepository $principals,
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
        /** @var list<string> providers with a key; empty — not checked */
        private readonly array $providers = [],
        /** remembers which run wrote each message, so a reply names its run */
        private readonly ?\OpenCCK\Domain\Repository\RunMessageRepository $runMessages = null,
        /** obligations to deliver final answers */
        private readonly ?\OpenCCK\Domain\Repository\DeliveryRepository $answers = null,
    ) {}

    public function useApprovals(ApprovalService $approvals): void {
        $this->approvals = $approvals;
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
     * spec: BRIEF.md#часть-3--фоновое-делегирование — delivery
     *
     * @param \Closure(int): void $onDelivered gets the run the text started
     */
    public function deliver(Principal $principal, int $conversationId, string $text, \Closure $onDelivered): void {
        $id = $this->nextDelivery++;
        $this->deliveries[$id] = async(function () use ($id, $principal, $conversationId, $text, $onDelivered): void {
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
                    // A delivered report is the agent's own text, often quoting the web: no recall on it.
                    $runId = $this->start($principal, $conversationId, $text, null, mayJoin: false, recall: false);
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
    /** @param bool $outside the message carries data from outside (files): the run that takes it is marked */
    public function submit(Principal $principal, IncomingMessage $message, int $conversationId, bool $silenceAllowed = false, bool $outside = false): void {
        // spec: BRIEF.md#telegram-группы-каналы-и-темы — the answer goes to the forum topic the message came from
        $this->threads[$conversationId] = $message->threadId;
        // the batch may stay silent only if nobody in it asked the agent
        $this->silence[$conversationId] = isset($this->pending[$conversationId]) ? ($this->silence[$conversationId] ?? false) && $silenceAllowed : $silenceAllowed;
        $text = trim($message->text);
        $this->audience()->remember($conversationId, $message->isPrivateChat);
        // A run being stopped takes no more steering: the message waits and starts the next run.
        $run = isset($this->stopping[$conversationId]) ? null : $this->runs->active($conversationId);
        // spec: BRIEF.md#подтверждения-и-запреты — a text from the owner while the run waits is a denial with that reason
        if ($run !== null && $run->status === RunStatus::WaitingApproval && $principal->isOwner() && $this->approvals?->denyPending($run->id, $text, t('agent.run_scheduler.otkazano_vladelets_otvetil_tekstom')) === true) {
            $this->resumeRun($run->id);
            return;
        }
        if ($run !== null) {
            if ($outside) {
                // spec: BRIEF.md#входящие-вложения — files are data from outside: no saved grant in this run from now on
                $this->runs->markOutside($run->id, 'attachments');
            }
            $this->transcript->inbox($run->id, $text, $message->messageId);
            return;
        }
        $now = microtime(true);
        if (isset($this->pending[$conversationId])) {
            $this->pending[$conversationId]['texts'][] = $text;
            $this->pending[$conversationId]['last'] = $now;
            $this->pending[$conversationId]['outside'] = $this->pending[$conversationId]['outside'] || $outside;
            // someone asked: the batch no longer waits for the group to fall silent
            $this->pending[$conversationId]['quiet'] = $this->pending[$conversationId]['quiet'] && $silenceAllowed;
            return;
        }
        $this->pending[$conversationId] = [
            'texts' => [$text],
            'first' => $now,
            'last' => $now,
            'message' => $message,
            'principal' => $principal,
            'quiet' => $silenceAllowed,
            'outside' => $outside,
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
                    // spec: BRIEF.md#часть-3--фоновое-делегирование — a background worker goes on with its delegation
                    continue;
                }
                if ($run->parentRunId !== null) {
                    // spec: BRIEF.md#часть-1--синхронное-делегирование — a worker belonged to a delegation call that the
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
            $runId = $this->start($pending['principal'], $conversationId, implode("\n\n", $pending['texts']), $pending['message']->messageId);
            if ($runId !== null && $pending['outside']) {
                $this->runs->markOutside($runId, 'attachments');
            }
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
    /** spec: BRIEF.md#самоулучшение-решено-2026-11-18 — the active notes the owner accepted come into new prompts */
    /** spec: BRIEF.md#сеть-агентов — the agent knows its own address and how a neighbour connects to it */
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
     * The forum topic answers go to: the conversation's own topic (kept in the base, so a run resumed after a restart
     * answers in it), else the thread of the last message (a reply chain in an ordinary group).
     */
    private function thread(ConversationMeta $meta, int $conversationId): ?string {
        return $meta->threadId !== '' ? $meta->threadId : ($this->threads[$conversationId] ?? null);
    }

    private function project(Principal $principal, string $channel, string $chatId, string $threadId = ''): string {
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

    /** @var list<Closure(Run, Principal): void> told when a run ends, in the background */
    private array $afterRun = [];

    /**
     * Something to do after a run ended (the background proposal of facts): it does not hold the conversation.
     *
     * @param Closure(Run, Principal): void $then
     */
    public function useAfterRun(Closure $then): void {
        $this->afterRun[] = $then;
    }

    /** @param ConversationAudience|null $audience shared with the memory tools */
    public function useMemory(MemoryContext $memory, ?ConversationAudience $audience = null): void {
        $this->memory = $memory;
        $this->audience = $audience ?? $this->audience;
    }

    private function audience(): ConversationAudience {
        return $this->audience ??= new ConversationAudience($this->conversations);
    }

    /** spec: BRIEF.md#контекст-по-месту-и-аудитории — layer 1 is the owner's, so only the owner's private chat gets it */
    /** The active notes the owner accepted ('' when none); the prompt text kept with the conversation records them. */
    private function notes(): string {
        try {
            return $this->notes?->active() ?? '';
        } catch (Throwable $e) {
            $this->logger->warning('Prompt notes were not read', ['error' => $e::class]);
            return '';
        }
    }

    private function core(Principal $principal, int $conversationId): string {
        if ($this->memory === null || !$this->audience()->isPrivate($conversationId)) {
            return '';
        }
        try {
            // spec: BRIEF.md#несколько-пользователей — a participant gets their own layer 1, never the owner's
            return $this->memory->core($principal->isOwner() ? \OpenCCK\Memory\App\Audience::OWNER : \OpenCCK\Memory\App\Audience::principal($principal->id));
        } catch (Throwable $e) {
            $this->logger->warning('Memory core is not available', ['error' => $e::class]);
            return '';
        }
    }

    /** The facts that answer the text go after it as reference data; memory failing never stops the run. */
    /**
     * spec: BRIEF.md#скиллы-и-mcp — a change of the catalog in the middle of a conversation is a one-off note on the
     *       next message, the prompt stays as it was
     */
    private function withSkillNote(Principal $principal, ConversationMeta $meta, int $conversationId, string $text): string {
        if ($this->skills === null || $principal->role === Role::Visitor) {
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

    private function withFacts(Principal $principal, ConversationMeta $meta, string $text): string {
        if ($this->memory === null) {
            return $text;
        }
        try {
            $facts = $this->memory->facts($text, $this->audience()->scopes($principal, $meta->id), $meta->id);
        } catch (Throwable $e) {
            $this->logger->warning('Memory recall failed', ['conversation' => $meta->id, 'error' => $e::class]);
            return $text;
        }
        return $facts === '' ? $text : $text . "\n\n" . UntrustedWrapper::wrap('memory', t('agent.run_scheduler.iz_pamyati_spravochnye_dannye_ne') . $facts);
    }

    /** @return int|null the run the text started; null when it joined another run or nothing started */
    private function start(Principal $principal, int $conversationId, string $text, ?string $messageId, bool $mayJoin = true, bool $recall = true): ?int {
        $meta = $this->conversations->meta($conversationId);
        if ($meta === null) {
            $this->logger->error('Conversation not found', ['conversation' => $conversationId]);
            return null;
        }
        $model = $meta->model ?? $this->model;
        // spec: BRIEF.md#провайдеры-моделей-openrouter-и-openai — the conversation was opened with a provider whose key
        // is gone: its runs go to the default model instead of failing every time
        if ($meta->systemPrompt !== null && $this->providers !== [] && !in_array(ModelRef::parse($model)->provider, $this->providers, true)) {
            $this->logger->warning('Conversation model has no provider key; the default one is used', ['conversation' => $conversationId, 'model' => $model]);
            $model = $this->model;
        }
        if ($meta->systemPrompt === null) {
            // spec: BRIEF.md#модели — the model is chosen when the conversation opens and kept till its end
            $model = $this->router !== null ? (string) $this->router->choose($conversationId, $text) : $this->model;
            $skills = '';
            if ($this->skills !== null && $principal->role !== Role::Visitor) {
                try {
                    $skills = $this->skills->index($principal, $this->projects?->active($meta->channel, $meta->chatId, $meta->threadId));
                    $this->skillSnapshots[$conversationId] = $skills;
                } catch (Throwable $e) {
                    $this->logger->warning('Skills index is not available', ['error' => $e::class]);
                }
            }
            $notes = $this->notes();
            $this->conversations->setMeta($conversationId, PromptBuilder::system($principal, new DateTimeImmutable(), $this->core($principal, $conversationId), $this->project($principal, $meta->channel, $meta->chatId, $meta->threadId), $skills, $meta->channel, $notes, $this->instance), $model, SystemPrompt::VERSION);
            $model = $this->conversations->meta($conversationId)->model ?? $model;
        }
        $policy = $this->policies->forPrincipal($principal);
        // spec: BRIEF.md#подтверждения-и-запреты — in a group other people's words are in the context: saved grants do not apply
        $run = $this->runs->start($conversationId, $principal->id, $model, $policy->run, null, str_starts_with($meta->chatId, '-') ? 'group chat' : null);
        if ($run === null) {
            // Lost a race with another start: the text joins the run that won.
            $active = $this->runs->active($conversationId);
            if ($active !== null && $mayJoin) {
                $this->transcript->inbox($active->id, $text, $messageId);
            }
            return null;
        }
        try {
            $this->transcript->appendUser($conversationId, $run->id, $this->withSkillNote($principal, $meta, $conversationId, $recall ? $this->withFacts($principal, $meta, $text) : $text), $messageId);
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

    private function launch(Run $run, Principal $principal, ChannelRunObserver $observer): void {
        $conversationId = $run->conversationId;
        $cancellation = new DeferredCancellation();
        $policy = $this->policies->forPrincipal($principal);
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
                    // Only our own entry: a newer run of the conversation may have started meanwhile.
                    if (($this->active[$conversationId]['cancellation'] ?? null) === $cancellation) {
                        unset($this->active[$conversationId], $this->stopping[$conversationId]);
                    }
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
    private function carryOver(Run $run, Principal $principal): void {
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
