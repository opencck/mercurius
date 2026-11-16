<?php

declare(strict_types=1);

namespace OpenCCK\App\Agent;

use Closure;
use Mercurius\Messenger\Channel;
use Mercurius\Messenger\OutgoingMessage;
use Mercurius\Messenger\ParseMode;
use Mercurius\Messenger\TextSplitter;
use OpenCCK\Domain\Agent\Run;
use OpenCCK\Domain\Agent\StopReason;

/**
 * Shows a run in the chat with one status message: a placeholder, then the current step, then the streamed answer;
 * the final text replaces it, long text goes on in new messages. Edits are throttled as in the plain chat.
 *
 * spec: BRIEF.md#канал — one status message edited as the run goes
 */
final class ChannelRunObserver implements RunObserver {
    public const PLACEHOLDER = '…';

    private ?string $messageId = null;
    private bool $started = false;
    private string $text = '';
    private string $shown = '';
    private float $lastEdit = 0.0;
    /** @var Closure(): float */
    private readonly Closure $clock;

    /** @param (Closure(): float)|null $clock */
    public function __construct(
        private readonly Channel $channel,
        private readonly string $chatId,
        private readonly ?string $replyTo = null,
        private readonly float $editInterval = 1.5,
        ?Closure $clock = null,
    ) {
        $this->clock = $clock ?? static fn(): float => microtime(true);
    }

    /** Sends the placeholder; called when the run starts or resumes. */
    public function start(?string $notice = null): void {
        $this->started = true;
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
        if ($this->messageId !== null) {
            $this->channel->edit($this->chatId, $this->messageId, $first, $mode);
        } else {
            $this->channel->send(new OutgoingMessage($this->chatId, $first, $this->replyTo, $mode));
        }
        foreach ($parts as $part) {
            $this->channel->send(new OutgoingMessage($this->chatId, $part, parseMode: $mode));
        }
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
        $this->channel->edit($this->chatId, $messageId, mb_strlen($text) > $limit ? mb_substr($text, 0, $limit - 2) . ' ' . self::PLACEHOLDER : $text, $mode);
        $this->shown = $this->text;
        $this->lastEdit = $now;
    }
}
