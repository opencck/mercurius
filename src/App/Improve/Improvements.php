<?php

declare(strict_types=1);

namespace OpenCCK\App\Improve;

use Closure;
use Mercurius\Messenger\Button;
use Mercurius\Messenger\CallbackHandler;
use Mercurius\Messenger\Channel;
use Mercurius\Messenger\IncomingCallback;
use Mercurius\Messenger\OutgoingMessage;
use OpenCCK\App\Agent\Tool\SecretMasker;
use OpenCCK\Domain\Repository\ImprovementRepository;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

use function Amp\async;
use function Mercurius\I18n\t;

/**
 * The agent proposes, the owner decides (owner's decision 2026-11-18). A proposal is a card in the owner's chat:
 * - `prompt` — a new version of the notes to the system prompt; on "accept" the evaluation set runs the current
 *   version against the candidate, and the candidate becomes active only when no category got worse beyond the noise;
 * - `setting` — a value of a setting, kept on "accept" (applies after a restart);
 * - `idea` — anything else (code, tools, rights): kept for whoever develops Mercurius; the agent changes nothing.
 * Nothing global changes without the owner's press.
 *
 * spec: BRIEF.md#самоулучшение-решено-2026-11-18
 * spec: BRIEF.md#границы-самоулучшения — the core changes only through the owner; the agent may propose
 */
final class Improvements implements CallbackHandler {
    public const PREFIX = 'im';
    public const KINDS = ['prompt', 'setting', 'idea'];

    /**
     * @param Closure(string, string): GateVerdict $gate the current notes and the candidate → the verdict of the set
     * @param Closure(string, string): string $setSetting key and value → what happened (throws DomainException)
     */
    public function __construct(
        private readonly ImprovementRepository $store,
        private readonly PromptNotes $notes,
        private readonly Channel $channel,
        private readonly string $ownerChatId,
        private readonly Closure $gate,
        private readonly Closure $setSetting,
        private readonly LoggerInterface $logger = new NullLogger(),
        /** the evaluation runs in the background; tests wait for it */
        private readonly bool $background = false,
    ) {}

    /** Keeps a proposal and shows it to the owner; the number of the proposal. */
    public function propose(string $kind, string $title, string $text, string $reason, ?string $key = null): int {
        $id = $this->store->propose($kind, mb_substr(trim($title), 0, 120), mb_substr(trim($text), 0, PromptNotes::MAX), mb_substr(trim($reason), 0, 1000), $key);
        $this->card($id, $kind, $title, $text, $reason, $key);
        $this->logger->info('Improvement proposed', ['id' => $id, 'kind' => $kind]);
        return $id;
    }

    /** Shows the proposals the owner has not decided again, with their buttons; the number of cards sent. */
    public function showOpen(): int {
        $open = $this->store->open();
        foreach ($open as $p) {
            $this->card($p['id'], $p['kind'], $p['title'], $p['body'], $p['reason'], $p['key']);
        }
        return count($open);
    }

    private function card(int $id, string $kind, string $title, string $text, string $reason, ?string $key): void {
        $body = match ($kind) {
            'setting' => sprintf('%s = %s', (string) $key, $text),
            'prompt' => $this->diff($text),
            default => $text,
        };
        $card = sprintf(t('improve.card'), $id, t('improve.kind.' . $kind), $title, SecretMasker::mask(mb_substr($body, 0, 2500)), SecretMasker::mask($reason))
            . ($kind === 'prompt' ? "\n\n" . t('improve.card_prompt') : '');
        $this->channel->send(new OutgoingMessage($this->ownerChatId, $card, disableLinkPreview: true, buttons: [[
            new Button(t('improve.accept'), sprintf('%s:%d:a', self::PREFIX, $id)),
            new Button(t('improve.reject'), sprintf('%s:%d:r', self::PREFIX, $id)),
        ]]));
    }

    public function handleCallback(IncomingCallback $callback): void {
        if (preg_match('~^im:(\d+):([ar])$~', $callback->data, $m) !== 1) {
            $this->channel->answerCallback($callback->callbackId);
            return;
        }
        $id = (int) $m[1];
        $proposal = $this->store->find($id);
        if ($proposal === null) {
            $this->channel->answerCallback($callback->callbackId, t('improve.gone'));
            return;
        }
        if ($m[2] === 'r') {
            $done = $this->store->move($id, ['proposed'], 'rejected');
            $this->channel->answerCallback($callback->callbackId, $done ? t('improve.rejected') : t('improve.decided'));
            if ($done && $callback->messageId !== null) {
                $this->channel->edit($callback->chatId, $callback->messageId, sprintf(t('improve.closed'), $id, $proposal['title'], t('improve.rejected')));
            }
            return;
        }
        $next = $proposal['kind'] === 'prompt' ? 'checking' : 'accepted';
        if (!$this->store->move($id, ['proposed'], $next)) {
            $this->channel->answerCallback($callback->callbackId, t('improve.decided'));
            return;
        }
        $this->channel->answerCallback($callback->callbackId, $proposal['kind'] === 'prompt' ? t('improve.checking') : t('improve.accepted'));
        if ($callback->messageId !== null) {
            $this->channel->edit($callback->chatId, $callback->messageId, sprintf(t('improve.closed'), $id, $proposal['title'], $proposal['kind'] === 'prompt' ? t('improve.checking') : t('improve.accepted')));
        }
        match ($proposal['kind']) {
            'prompt' => $this->background ? async(fn() => $this->checkPrompt($proposal)) : $this->checkPrompt($proposal),
            'setting' => $this->applySetting($proposal),
            default => $this->tell(sprintf(t('improve.idea_kept'), $id)),
        };
    }

    /** @param array{id: int, kind: string, title: string, body: string, reason: string, key: ?string, status: string} $proposal */
    private function checkPrompt(array $proposal): void {
        try {
            $verdict = ($this->gate)($this->notes->active(), $proposal['body']);
        } catch (Throwable $e) {
            $this->store->move($proposal['id'], ['checking'], 'failed', 'evaluation failed: ' . $e::class);
            $this->logger->error('Evaluation of a prompt change failed', ['id' => $proposal['id'], 'error' => $e::class, 'message' => mb_substr($e->getMessage(), 0, 300)]);
            $this->tell(sprintf(t('improve.gate_error'), $proposal['id']));
            return;
        }
        if (!$verdict->passed) {
            $this->store->move($proposal['id'], ['checking'], 'failed', $verdict->report);
            $this->tell(sprintf(t('improve.gate_failed'), $proposal['id'], $verdict->report));
            return;
        }
        $version = $this->notes->add($proposal['body'], $proposal['title'] . ': ' . $proposal['reason']);
        $this->notes->activate($version);
        $this->store->move($proposal['id'], ['checking'], 'accepted', $verdict->report);
        $this->tell(sprintf(t('improve.gate_passed'), $proposal['id'], $version, $verdict->report));
    }

    /** @param array{id: int, kind: string, title: string, body: string, reason: string, key: ?string, status: string} $proposal */
    private function applySetting(array $proposal): void {
        try {
            $this->tell(($this->setSetting)((string) $proposal['key'], $proposal['body']));
        } catch (\DomainException $e) {
            $this->store->move($proposal['id'], ['accepted'], 'failed', $e->getMessage());
            $this->tell($e->getMessage());
        }
    }

    /** What changes against the active version: the new text whole, the old one shortly. */
    private function diff(string $text): string {
        $current = $this->notes->active();
        return ($current === '' ? '' : t('improve.was') . "\n" . mb_substr($current, 0, 800) . "\n\n" . t('improve.will_be') . "\n") . $text;
    }

    private function tell(string $text): void {
        $this->channel->send(new OutgoingMessage($this->ownerChatId, $text));
    }
}
