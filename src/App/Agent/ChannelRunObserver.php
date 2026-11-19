<?php

declare(strict_types=1);

namespace OpenCCK\App\Agent;

use Closure;
use Mercurius\Messenger\Channel;
use Mercurius\Messenger\OutgoingMessage;
use Mercurius\Messenger\SendResult;
use Mercurius\Messenger\SendErrorKind;
use Mercurius\Messenger\ParseMode;
use Mercurius\Messenger\TextSplitter;
use OpenCCK\Domain\Agent\Run;
use OpenCCK\Domain\Agent\StopReason;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Revolt\EventLoop;

use function Amp\delay;

use function Mercurius\I18n\t;

/**
 * Shows a run in the chat with one status message: a placeholder, then the current step, then the streamed answer;
 * the final text replaces it, long text goes on in new messages. Edits are throttled as in the plain chat.
 *
 * spec: BRIEF.md#канал — one status message edited as the run goes
 */
final class ChannelRunObserver implements RunObserver {
    public const PLACEHOLDER = '…';
    /** What the preview says when the answer of a long run comes as a new message. */
    public const ANSWER_BELOW = 'agent.channel_run_observer.answer_below';

    private ?string $messageId = null;
    private bool $started = false;
    private string $text = '';
    private string $shown = '';
    private float $lastEdit = 0.0;
    private float $startedAt = 0.0;
    /** @var Closure(): float */
    private readonly Closure $clock;

    /** @param (Closure(): float)|null $clock */
    public function __construct(
        private readonly Channel $channel,
        private readonly string $chatId,
        private readonly ?string $replyTo = null,
        private readonly float $editInterval = 1.5,
        ?Closure $clock = null,
        /** a run longer than this gets its answer as a new message: an edit does not notify */
        private readonly float $freshAfter = 60.0,
        /** the longest flood wait the final message sits out before going another way */
        private readonly float $maxFloodWait = 120.0,
        private readonly LoggerInterface $logger = new NullLogger(),
        /** the forum topic the conversation goes on in; every message of the run stays in it */
        private readonly ?string $threadId = null,
        /** remembers which run wrote each delivered message; null — not remembered */
        private readonly ?\OpenCCK\Domain\Repository\RunMessageRepository $messages = null,
        /** nobody waits for an answer (an unaddressed message of a free group): [SILENT] may end the run quietly */
        private readonly bool $silenceAllowed = false,
        /** obligations to deliver the final answer, sent again after a restart; null — not kept */
        private readonly ?\OpenCCK\Domain\Repository\DeliveryRepository $deliveries = null,
    ) {
        $this->clock = $clock ?? static fn(): float => microtime(true);
    }

    /** Sends the placeholder; called when the run starts or resumes. */
    public function start(?string $notice = null): void {
        $this->started = true;
        $this->startedAt = ($this->clock)();
        $this->channel->typing($this->chatId);
        $result = $this->channel->send(new OutgoingMessage($this->chatId, $notice ?? self::PLACEHOLDER, $this->replyTo, threadId: $this->threadId));
        $this->messageId = $result->success ? $result->messageId : null;
    }

    /**
     * The status message keeps the commentary as it is; the run goes on in a new status message below it, so the
     * answer comes after what was said before it.
     */
    public function commentary(Run $run, string $text): void {
        $this->text = '';
        $this->shown = '';
        if (trim($text) === '' || str_starts_with(ltrim($text), '[SILENT]') || !$this->started) {
            return;
        }
        $messageId = $this->messageId;
        $parts = (new TextSplitter($this->channel->capabilities()->maxMessageLength))->split(trim($text));
        $first = array_shift($parts) ?? trim($text);
        // a commentary sitting out a flood counts as kept: it is not sent a second time
        $kept = $messageId !== null && $this->delivered(fn(): SendResult => $this->channel->edit($this->chatId, $messageId, $first, ParseMode::Markdown)) !== false;
        if ($kept) {
            $this->remember($run, $messageId);
        } else {
            array_unshift($parts, $first);
        }
        foreach ($parts as $part) {
            $this->sendPart($run, $part, ParseMode::Markdown);
        }
        $result = $this->channel->send(new OutgoingMessage($this->chatId, self::PLACEHOLDER, threadId: $this->threadId));
        $this->messageId = $result->success ? $result->messageId : null;
        $this->lastEdit = 0.0;
    }

    public function toolStarted(Run $run, string $name, string $argsPreview): void {
        // Text the model wrote before calling a tool is a thought, not the answer: the next call streams anew.
        $this->text = '';
        // ref: references/hermes-agent/gateway/display_config.py@e96221fa:L36-L37 (live_status "verb") — paths and arguments
        //      stay out of a shared chat; a group is told only which tool runs
        $this->show($this->shared() ? sprintf(t('agent.channel_run_observer.running_tool'), $name) : sprintf(t('agent.channel_run_observer.vypolnyayu'), $name, $argsPreview), ParseMode::Plain, force: true);
    }

    public function textDelta(Run $run, string $text): void {
        $this->text .= $text;
        $head = ltrim($this->text);
        // A silent answer is not shown while it streams either: "[SILENT]" or the start of it.
        $silent = str_starts_with($head, '[SILENT]') || ($head !== '' && strncmp('[SILENT]', $head, strlen($head)) === 0);
        if (!$silent && trim($this->text) !== '' && $this->text !== $this->shown) {
            $this->show($this->text . ' ' . self::PLACEHOLDER, ParseMode::Markdown);
        }
    }

    /**
     * The status message a waiting run left says the decision came: «waiting for the owner» does not stay forever.
     *
     * spec: BRIEF.md#решения-по-доработкам-2026-11-18
     */
    public function settled(string $text): void {
        if ($this->messageId !== null) {
            $this->channel->edit($this->chatId, $this->messageId, $text);
        }
    }

    public function waiting(Run $run, string $text): void {
        $this->text = '';
        $this->show($text, ParseMode::Plain, force: true);
    }

    public function finished(Run $run, string $finalText, ?StopReason $reason): void {
        // spec: BRIEF.md#telegram-группы-каналы-и-темы — in a free group the agent may stay silent
        // ref: references/hermes-agent/gateway/response_filters.py@e96221fa:L241-L256 (silence_allowed) — only a turn nobody
        //      waits for may vanish; someone who asked gets a visible answer
        if ($reason === StopReason::Answered && str_starts_with(ltrim($finalText), '[SILENT]') && !$this->silenceAllowed) {
            $rest = trim(substr(ltrim($finalText), strlen('[SILENT]')));
            $finalText = $rest === '' ? t('agent.channel_run_observer.nothing_to_add') : $rest;
        }
        if ($reason === StopReason::Answered && str_starts_with(ltrim($finalText), '[SILENT]')) {
            if ($this->messageId !== null) {
                $this->channel->delete($this->chatId, $this->messageId);
            }
            $this->logger->info('Run stayed silent', ['run' => $run->id, 'chat' => $this->chatId]);
            return;
        }
        if (!$this->started) {
            $this->start();
        }
        $mode = $reason === StopReason::Answered ? ParseMode::Markdown : ParseMode::Plain;
        $parts = (new TextSplitter($this->channel->capabilities()->maxMessageLength))->split($finalText);
        $first = array_shift($parts) ?? $finalText;
        // spec: BRIEF.md#агентский-цикл-и-харнесс — the answer is an obligation before it is sent
        $obligation = $this->obligation($run, $finalText, $mode);
        $messageId = $this->messageId;
        // ref: references/hermes-agent/gateway/stream_consumer_transport.py@e96221fa:L247-L255 (_should_send_fresh_final)
        //      — the answer of a long run goes as a new message, so the owner is notified
        $fresh = $messageId !== null && ($this->clock)() - $this->startedAt >= $this->freshAfter;
        if ($messageId !== null && !$fresh) {
            $then = function (bool $ok) use ($run, $messageId, $parts, $mode, $obligation): void {
                $ok ? $this->firstDelivered($run, $messageId, null, $parts, $mode, $obligation) : $this->notDelivered($run, $obligation);
            };
            $edited = $this->delivered(fn(): SendResult => $this->channel->edit($this->chatId, $messageId, $first, $mode), $then);
            if ($edited === null) {
                return;
            }
            if ($edited) {
                $this->firstDelivered($run, $messageId, null, $parts, $mode, $obligation);
                return;
            }
        }
        // The preview points below only once something is below.
        $preview = $fresh ? $messageId : null;
        $then = function (bool $ok) use ($run, $preview, $parts, $mode, $obligation): void {
            $ok ? $this->firstDelivered($run, null, $preview, $parts, $mode, $obligation) : $this->notDelivered($run, $obligation);
        };
        $sent = $this->sendPart($run, $first, $mode, $this->replyTo, $then);
        if ($sent !== null) {
            $then($sent);
        }
    }

    /**
     * The first part of the answer is in the chat: the rest follows it in order.
     *
     * @param list<string> $parts the parts after the first
     */
    private function firstDelivered(Run $run, ?string $edited, ?string $preview, array $parts, ParseMode $mode, ?int $obligation): void {
        if ($edited !== null) {
            $this->remember($run, $edited);
        }
        $this->logger->info('Run answer delivered', ['run' => $run->id, 'chat' => $this->chatId, 'how' => $edited !== null ? 'edit' : 'send'] + ($edited !== null ? ['message' => $edited] : []));
        if ($preview !== null) {
            $this->channel->edit($this->chatId, $preview, \Mercurius\I18n\t(self::ANSWER_BELOW), ParseMode::Plain);
        }
        $this->note($obligation, fn(int $id) => $this->deliveries?->progress($id, 1));
        $this->sendRest($run, $parts, $mode, $obligation, 1);
    }

    /**
     * Parts go in order: after a lost part the rest waits for the redelivery, which goes on from it. A part that sits
     * out a long flood carries the rest with it, and the obligation is closed only after the last one is really sent.
     *
     * @param list<string> $left
     */
    private function sendRest(Run $run, array $left, ParseMode $mode, ?int $obligation, int $sent): void {
        while ($left !== []) {
            $part = array_shift($left);
            $rest = $left;
            $done = $sent + 1;
            $later = function (bool $ok) use ($run, $rest, $mode, $obligation, $done): void {
                if (!$ok) {
                    $this->notDelivered($run, $obligation);
                    return;
                }
                $this->note($obligation, fn(int $id) => $this->deliveries?->progress($id, $done));
                $this->sendRest($run, $rest, $mode, $obligation, $done);
            };
            $result = $this->sendPart($run, $part, $mode, null, $later);
            if ($result === null) {
                return;
            }
            if (!$result) {
                $this->notDelivered($run, $obligation);
                return;
            }
            $this->note($obligation, fn(int $id) => $this->deliveries?->progress($id, $done));
            $sent = $done;
        }
        $this->note($obligation, fn(int $id) => $this->deliveries?->delivered($id));
    }

    private function notDelivered(Run $run, ?int $obligation): void {
        $this->logger->warning('Run answer was not delivered', ['run' => $run->id, 'chat' => $this->chatId]);
        $this->note($obligation, fn(int $id) => $this->deliveries?->failed($id, 'not delivered'));
    }

    /** The obligation of this answer; null when none is kept or it could not be written. */
    private function obligation(Run $run, string $text, ParseMode $mode): ?int {
        if ($this->deliveries === null) {
            return null;
        }
        try {
            $id = $this->deliveries->open($run->id, $this->channel->name(), $this->chatId, $this->threadId, $this->replyTo, $text, $mode->value);
            $this->deliveries->attempting($id);
            return $id;
        } catch (\Throwable $e) {
            $this->logger->warning('Delivery obligation was not recorded', ['run' => $run->id, 'error' => $e::class]);
            return null;
        }
    }

    /** @param \Closure(int): mixed $write a ledger write: best effort, never blocks the answer */
    private function note(?int $obligation, \Closure $write): void {
        if ($obligation === null) {
            return;
        }
        try {
            $write($obligation);
        } catch (\Throwable $e) {
            $this->logger->warning('Delivery obligation was not updated', ['error' => $e::class]);
        }
    }

    /** A group or a channel: Telegram gives them negative ids. */
    private function shared(): bool {
        return str_starts_with($this->chatId, '-');
    }

    /**
     * Sends one message of the run and remembers that the run wrote it.
     *
     * @param (Closure(bool): void)|null $later gets the result when the message goes after a long flood
     * @return bool|null null — goes after a long flood
     */
    private function sendPart(Run $run, string $text, ParseMode $mode, ?string $replyTo = null, ?Closure $later = null): ?bool {
        /** @var \ArrayObject<int, string> $id the id of the sent message, once there is one */
        $id = new \ArrayObject();
        $sent = $this->delivered(function () use ($text, $mode, $replyTo, $id): SendResult {
            $result = $this->channel->send(new OutgoingMessage($this->chatId, $text, $replyTo, $mode, threadId: $this->threadId));
            if ($result->messageId !== null) {
                $id[0] = $result->messageId;
            }
            return $result;
        }, function (bool $ok) use ($run, $id, $later): void {
            if ($ok && isset($id[0])) {
                $this->remember($run, $id[0]);
            }
            if ($later !== null) {
                $later($ok);
            }
        });
        if ($sent === true && isset($id[0])) {
            $this->remember($run, $id[0]);
        }
        return $sent;
    }

    private function remember(Run $run, ?string $messageId): void {
        if ($messageId === null || $this->messages === null) {
            return;
        }
        try {
            $this->messages->record($run->id, $this->channel->name(), $this->chatId, $messageId);
        } catch (\Throwable $e) {
            $this->logger->warning('Run message was not remembered', ['run' => $run->id, 'error' => $e::class]);
        }
    }

    /**
     * The final message must not be lost: a short flood is waited out once, then the caller tries another way.
     *
     * ref: references/hermes-agent/gateway/stream_consumer_fallback.py@e96221fa:L206 (_send_fallback_final) — the unseen
     *      answer goes as a new message; a flood up to a few seconds is waited out (L475-L487)
     *
     * @param Closure(): SendResult $attempt
     * @param (Closure(bool): void)|null $later gets the result of the attempt made after a long flood
     * @return bool|null null — the attempt is made after a long flood
     */
    private function delivered(Closure $attempt, ?Closure $later = null): ?bool {
        $result = $attempt();
        if ($result->success) {
            return true;
        }
        if ($result->errorKind === SendErrorKind::RateLimited && $result->retryAfter !== null) {
            if ($result->retryAfter <= $this->maxFloodWait) {
                delay((float) $result->retryAfter);
                return $attempt()->success;
            }
            // A long flood: the answer still goes, once the chat may be written to again.
            // Unreferenced: a pending answer does not keep a stopping process alive.
            $this->logger->notice('Run answer waits out a flood', ['chat' => $this->chatId, 'seconds' => $result->retryAfter]);
            $logger = $this->logger;
            $chatId = $this->chatId;
            // The obligation stays open meanwhile: a restart before the timer redelivers the answer.
            EventLoop::unreference(EventLoop::delay((float) $result->retryAfter + 1.0, static function () use ($attempt, $logger, $chatId, $later): void {
                $ok = $attempt()->success;
                if (!$ok) {
                    $logger->warning('Run answer was not delivered after a flood', ['chat' => $chatId]);
                }
                if ($later !== null) {
                    $later($ok);
                }
            }));
            return null;
        }
        return false;
    }

    private function show(string $text, ParseMode $mode, bool $force = false): void {
        $messageId = $this->messageId;
        if ($messageId === null) {
            return;
        }
        $now = ($this->clock)();
        if (!$force && $now - $this->lastEdit < $this->editInterval) {
            return;
        }
        // While streaming the text is cut, not split: continuations would multiply with every edit.
        $limit = $this->channel->capabilities()->maxMessageLength;
        $result = $this->channel->edit($this->chatId, $messageId, mb_strlen($text) > $limit ? mb_substr($text, 0, $limit - 2) . ' ' . self::PLACEHOLDER : $text, $mode);
        if (!$result->success) {
            $this->logger->notice('Run status was not shown', ['chat' => $this->chatId, 'message' => $messageId, 'kind' => $result->errorKind?->value]);
        }
        $this->shown = $this->text;
        $this->lastEdit = $now;
    }
}
