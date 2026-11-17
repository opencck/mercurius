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

/**
 * Shows a run in the chat with one status message: a placeholder, then the current step, then the streamed answer;
 * the final text replaces it, long text goes on in new messages. Edits are throttled as in the plain chat.
 *
 * spec: BRIEF.md#канал — one status message edited as the run goes
 */
final class ChannelRunObserver implements RunObserver {
    public const PLACEHOLDER = '…';
    /** What the preview says when the answer of a long run comes as a new message. */
    public const ANSWER_BELOW = 'Готово — ответ ниже.';

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
    ) {
        $this->clock = $clock ?? static fn(): float => microtime(true);
    }

    /** Sends the placeholder; called when the run starts or resumes. */
    public function start(?string $notice = null): void {
        $this->started = true;
        $this->startedAt = ($this->clock)();
        $this->channel->typing($this->chatId);
        $result = $this->channel->send(new OutgoingMessage($this->chatId, $notice ?? self::PLACEHOLDER, $this->replyTo));
        $this->messageId = $result->success ? $result->messageId : null;
    }

    public function toolStarted(Run $run, string $name, string $argsPreview): void {
        // Text the model wrote before calling a tool is a thought, not the answer: the next call streams anew.
        $this->text = '';
        $this->show(sprintf('Выполняю %s: %s …', $name, $argsPreview), ParseMode::Plain, force: true);
    }

    public function textDelta(Run $run, string $text): void {
        $this->text .= $text;
        if (trim($this->text) !== '' && $this->text !== $this->shown) {
            $this->show($this->text . ' ' . self::PLACEHOLDER, ParseMode::Markdown);
        }
    }

    public function waiting(Run $run, string $text): void {
        $this->text = '';
        $this->show($text, ParseMode::Plain, force: true);
    }

    public function finished(Run $run, string $finalText, ?StopReason $reason): void {
        if (!$this->started) {
            $this->start();
        }
        $mode = $reason === StopReason::Answered ? ParseMode::Markdown : ParseMode::Plain;
        $parts = (new TextSplitter($this->channel->capabilities()->maxMessageLength))->split($finalText);
        $first = array_shift($parts) ?? $finalText;
        $messageId = $this->messageId;
        // ref: references/hermes-agent/gateway/stream_consumer_transport.py@e96221fa:L247-L255 (_should_send_fresh_final)
        //      — the answer of a long run goes as a new message, so the owner is notified
        $fresh = $messageId !== null && ($this->clock)() - $this->startedAt >= $this->freshAfter;
        $delivered = $messageId !== null && !$fresh
            && $this->delivered(fn(): SendResult => $this->channel->edit($this->chatId, $messageId, $first, $mode));
        if ($delivered) {
            $this->logger->info('Run answer delivered', ['run' => $run->id, 'chat' => $this->chatId, 'how' => 'edit', 'message' => $messageId]);
        } else {
            $sent = $this->delivered(fn(): SendResult => $this->channel->send(new OutgoingMessage($this->chatId, $first, $this->replyTo, $mode)));
            if ($sent) {
                $this->logger->info('Run answer delivered', ['run' => $run->id, 'chat' => $this->chatId, 'how' => 'send']);
            }
            if (!$sent) {
                $this->logger->warning('Run answer was not delivered', ['run' => $run->id, 'chat' => $this->chatId]);
            }
            // The preview points below only once something is below.
            $preview = $fresh ? $messageId : null;
            if ($sent && $preview !== null) {
                $this->channel->edit($this->chatId, $preview, self::ANSWER_BELOW, ParseMode::Plain);
            }
        }
        foreach ($parts as $part) {
            $this->delivered(fn(): SendResult => $this->channel->send(new OutgoingMessage($this->chatId, $part, parseMode: $mode)));
        }
    }

    /**
     * The final message must not be lost: a short flood is waited out once, then the caller tries another way.
     *
     * ref: references/hermes-agent/gateway/stream_consumer_fallback.py@e96221fa:L206 (_send_fallback_final) — the unseen
     *      answer goes as a new message; a flood up to a few seconds is waited out (L475-L487)
     *
     * @param Closure(): SendResult $attempt
     */
    private function delivered(Closure $attempt): bool {
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
            EventLoop::unreference(EventLoop::delay((float) $result->retryAfter + 1.0, static function () use ($attempt, $logger, $chatId): void {
                if (!$attempt()->success) {
                    $logger->warning('Run answer was not delivered after a flood', ['chat' => $chatId]);
                }
            }));
            return true;
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
