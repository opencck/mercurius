<?php

declare(strict_types=1);

namespace OpenCCK\App\Service;

use Closure;
use Mercurius\Messenger\Button;
use Mercurius\Messenger\CallbackHandler;
use Mercurius\Messenger\Channel;
use Mercurius\Messenger\IncomingCallback;
use Mercurius\Messenger\OutgoingMessage;
use OpenCCK\Domain\Entity\Principal;
use OpenCCK\Domain\Entity\PrincipalKind;
use OpenCCK\Domain\Entity\PrincipalStatus;
use OpenCCK\Domain\Repository\PrincipalRepository;
use Psr\Log\LoggerInterface;

use function Mercurius\I18n\t;

/**
 * The owner passes ownership on: /owner <number> in the private chat shows a card, the owner's press passes it. The
 * former owner becomes an admin and keeps what is theirs; the new owner gets the agent.
 *
 * spec: BRIEF.md#несколько-пользователей — the owner passes ownership on, the personal stays with the former owner
 */
final class OwnershipTransfer implements CallbackHandler {
    public const PREFIX = 'ow';
    private const LIFETIME = 600;

    /** @var array<string, array{from: int, to: int, until: int}> nonce => the transfer the card asks for */
    private array $pending = [];
    /** @var Closure(): int */
    private readonly Closure $clock;

    /** @param (Closure(): int)|null $clock */
    public function __construct(
        private readonly PrincipalRepository $principals,
        private readonly Channel $channel,
        private readonly OwnerDirectory $owner,
        private readonly LoggerInterface $logger,
        ?Closure $clock = null,
    ) {
        $this->clock = $clock ?? static fn(): int => time();
    }

    /** Shows the card for /owner <number>; the text to answer with when there is no card. */
    public function ask(Principal $owner, string $chatId, string $argument): ?string {
        if (!ctype_digit(trim($argument))) {
            return t('ownership.usage');
        }
        $target = $this->principals->find((int) trim($argument));
        if ($target === null || !$this->eligible($owner, $target)) {
            return t('ownership.not_eligible');
        }
        $nonce = bin2hex(random_bytes(4));
        $this->pending[$nonce] = ['from' => $owner->id, 'to' => $target->id, 'until' => ($this->clock)() + self::LIFETIME];
        $this->channel->send(new OutgoingMessage($chatId, sprintf(t('ownership.card'), $target->id, $target->displayName ?? '—'), buttons: [[
            new Button(t('ownership.confirm'), self::PREFIX . ':' . $nonce . ':y'),
            new Button(t('ownership.cancel'), self::PREFIX . ':' . $nonce . ':n'),
        ]]));
        return null;
    }

    public function handleCallback(IncomingCallback $callback): void {
        if (preg_match('/^' . self::PREFIX . ':([0-9a-f]{8}):([yn])$/', $callback->data, $m) !== 1) {
            $this->channel->answerCallback($callback->callbackId);
            return;
        }
        $request = $this->pending[$m[1]] ?? null;
        unset($this->pending[$m[1]]);
        $text = match (true) {
            $request === null || $request['until'] < ($this->clock)() => t('ownership.expired'),
            $m[2] === 'n' => t('ownership.cancelled'),
            default => $this->pass($request['from'], $request['to']),
        };
        $this->channel->answerCallback($callback->callbackId, $text);
        if ($callback->messageId !== null) {
            $this->channel->edit($callback->chatId, $callback->messageId, $text);
        }
    }

    private function pass(int $from, int $to): string {
        $owner = $this->owner->principal();
        $target = $this->principals->find($to);
        // the press comes from the owner (CallbackRouter); the owner must still be the one who asked
        if ($owner === null || $owner->id !== $from || $target === null || !$this->eligible($owner, $target) || !$this->principals->transferOwnership($from, $to)) {
            return t('ownership.failed');
        }
        $this->owner->forget();
        $this->logger->notice('Ownership passed on', ['from' => $from, 'to' => $to]);
        $chat = $this->owner->chatId();
        $this->channel->send(new OutgoingMessage($chat, t('ownership.welcome')));
        return sprintf(t('ownership.done'), $target->id, $target->displayName ?? '—');
    }

    private function eligible(Principal $owner, Principal $target): bool {
        return $target->id !== $owner->id && $target->kind === PrincipalKind::Human && $target->status === PrincipalStatus::Active;
    }
}
