<?php

declare(strict_types=1);

namespace OpenCCK\App\Agent;

use Amp\DeferredCancellation;
use Amp\Future;
use DateTimeImmutable;
use Mercurius\Messenger\Channel;
use Mercurius\Messenger\IncomingMessage;
use OpenCCK\App\Service\PolicyService;
use OpenCCK\Domain\Agent\DangerClass;
use OpenCCK\Domain\Agent\Run;
use OpenCCK\Domain\Agent\StopReason;
use OpenCCK\Domain\Agent\ToolCallStatus;
use OpenCCK\Domain\Entity\Principal;
use OpenCCK\Domain\Repository\ConversationRepository;
use OpenCCK\Domain\Repository\PrincipalRepository;
use OpenCCK\Domain\Repository\RunRepository;
use OpenCCK\Domain\Repository\TranscriptRepository;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

use function Amp\async;
use function Amp\delay;

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
    public const RESUMED = 'Продолжаю задачу после перезапуска.';
    public const INTERRUPTED = 'Прервано перезапуском: неизвестно, выполнено ли действие. Проверь состояние, прежде чем повторять.';

    /** @var array<int, array{cancellation: DeferredCancellation, future: Future<void>}> conversation id => running fiber */
    private array $active = [];
    /** @var array<int, array{texts: list<string>, first: float, last: float, message: IncomingMessage, principal: Principal, future: Future<void>}> */
    private array $pending = [];

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
        private readonly float $editInterval = 1.5,
    ) {}

    /** Hands a message over and returns at once. */
    public function submit(Principal $principal, IncomingMessage $message, int $conversationId): void {
        $text = trim($message->text);
        $run = $this->runs->active($conversationId);
        if ($run !== null) {
            $this->transcript->inbox($run->id, $text, $message->messageId);
            return;
        }
        $now = microtime(true);
        if (isset($this->pending[$conversationId])) {
            $this->pending[$conversationId]['texts'][] = $text;
            $this->pending[$conversationId]['last'] = $now;
            return;
        }
        $this->pending[$conversationId] = [
            'texts' => [$text],
            'first' => $now,
            'last' => $now,
            'message' => $message,
            'principal' => $principal,
            'future' => async($this->startAfterDebounce(...), $conversationId),
        ];
    }

    /** Stops the conversation's run; false when nothing runs. */
    public function stop(int $conversationId): bool {
        if (isset($this->pending[$conversationId])) {
            $this->pending[$conversationId]['future']->ignore();
            unset($this->pending[$conversationId]);
            return true;
        }
        if (isset($this->active[$conversationId])) {
            $this->active[$conversationId]['cancellation']->cancel();
            return true;
        }
        return false;
    }

    /** Continues runs a restart interrupted. Write and irreversible calls that were running are not repeated. */
    public function resumeAll(): void {
        foreach ($this->runs->unfinished() as $run) {
            try {
                $this->resume($run);
            } catch (Throwable $e) {
                $this->logger->error('Could not resume a run', ['run' => $run->id, 'error' => $e::class]);
            }
        }
    }

    /** Cancels every run with a shutdown reason: they stay running in the database and resume on the next start. */
    public function shutdown(): void {
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
        while ($this->pending !== [] || $this->active !== []) {
            $futures = array_merge(array_column($this->pending, 'future'), array_column($this->active, 'future'));
            Future\awaitAll($futures);
        }
    }

    private function startAfterDebounce(int $conversationId): void {
        while (isset($this->pending[$conversationId])) {
            $pending = $this->pending[$conversationId];
            $wait = min($pending['last'] + $this->debounce, $pending['first'] + $this->debounceCap) - microtime(true);
            if ($wait <= 0) {
                break;
            }
            delay($wait);
        }
        $pending = $this->pending[$conversationId] ?? null;
        unset($this->pending[$conversationId]);
        if ($pending === null) {
            return;
        }
        $this->start($pending['principal'], $conversationId, implode("\n\n", $pending['texts']), $pending['message']->messageId);
    }

    private function start(Principal $principal, int $conversationId, string $text, ?string $messageId): void {
        $meta = $this->conversations->meta($conversationId);
        if ($meta === null) {
            $this->logger->error('Conversation not found', ['conversation' => $conversationId]);
            return;
        }
        if ($meta->systemPrompt === null) {
            $this->conversations->setMeta($conversationId, PromptBuilder::system($principal, new DateTimeImmutable()), $this->model);
        }
        $policy = $this->policies->forPrincipal($principal);
        $run = $this->runs->start($conversationId, $principal->id, $meta->model ?? $this->model, $policy->run);
        if ($run === null) {
            // Lost a race with another start: the text joins the run that won.
            $active = $this->runs->active($conversationId);
            if ($active !== null) {
                $this->transcript->inbox($active->id, $text, $messageId);
            }
            return;
        }
        $this->transcript->appendUser($conversationId, $run->id, $text, $messageId);
        $observer = new ChannelRunObserver($this->channel, $meta->chatId, $messageId, $this->editInterval);
        $observer->start();
        $this->launch($run, $principal, $observer);
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
                $this->transcript->appendToolResult($run->conversationId, $run->id, $call->callId, ToolCallStatus::Interrupted, self::INTERRUPTED);
            }
        }
        $this->logger->info('Resuming run after restart', ['run' => $run->id]);
        $observer = new ChannelRunObserver($this->channel, $meta->chatId, null, $this->editInterval);
        $observer->start(self::RESUMED);
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
                    return;
                } finally {
                    unset($this->active[$conversationId]);
                }
                if (!$final->status->isActive()) {
                    $this->carryOver($final, $principal);
                }
            }),
        ];
    }

    /** Steering notes that came after the run's last iteration start the next run instead of being lost. */
    private function carryOver(Run $run, Principal $principal): void {
        $left = $this->transcript->consumeInbox($run->id);
        if ($left === [] || $run->stopReason === StopReason::Stopped) {
            return;
        }
        $this->start($principal, $run->conversationId, implode("\n\n", $left), null);
    }
}
