<?php

declare(strict_types=1);

namespace OpenCCK\App\Agent;

use Mercurius\Messenger\Button;
use Mercurius\Messenger\CallbackHandler;
use Mercurius\Messenger\Channel;
use Mercurius\Messenger\IncomingCallback;
use Mercurius\Messenger\OutgoingMessage;
use OpenCCK\App\Agent\Tool\SecretMasker;
use OpenCCK\Memory\App\Proposals;
use OpenCCK\Memory\Domain\MemoryStore;
use OpenCCK\Memory\Domain\Proposal;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * A memory proposal is a card in the owner's chat: accept or reject, decided once. The run that proposed does not
 * wait; the change applies when the owner presses.
 *
 * spec: BRIEF.md#фаза-6-по-частям — the owner approves what goes into memory
 */
final class MemoryCards implements CallbackHandler {
    public const PREFIX = 'mp';

    public function __construct(
        private readonly Proposals $proposals,
        private readonly MemoryStore $store,
        private readonly Channel $channel,
        private readonly string $ownerChatId,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {}

    public function show(Proposal $proposal): bool {
        $result = $this->channel->send(new OutgoingMessage($this->ownerChatId, self::card($proposal), buttons: [[
            new Button('Принять', sprintf('%s:%d:a', self::PREFIX, $proposal->id)),
            new Button('Отклонить', sprintf('%s:%d:r', self::PREFIX, $proposal->id)),
        ]]));
        if (!$result->success || $result->messageId === null) {
            $this->logger->warning('Memory card was not delivered', ['proposal' => $proposal->id]);
            return false;
        }
        $this->store->setCard($proposal->id, $this->ownerChatId, $result->messageId);
        return true;
    }

    public function handleCallback(IncomingCallback $callback): void {
        if (preg_match('/^mp:(\d+):([ar])$/', $callback->data, $match) !== 1) {
            $this->channel->answerCallback($callback->callbackId);
            return;
        }
        $approve = $match[2] === 'a';
        $decided = $this->proposals->decide((int) $match[1], $approve);
        if ($decided === null) {
            $this->channel->answerCallback($callback->callbackId, 'Уже решено.');
            return;
        }
        $outcome = $approve ? 'Принято: память изменена.' : 'Отклонено.';
        $this->channel->answerCallback($callback->callbackId, $outcome);
        if ($callback->messageId !== null) {
            $this->channel->edit($callback->chatId, $callback->messageId, self::card($decided) . "\n\n" . $outcome);
        }
        $this->logger->info('Memory proposal decided', ['proposal' => $decided->id, 'kind' => $decided->kind, 'approved' => $approve]);
    }

    private static function card(Proposal $proposal): string {
        $what = match ($proposal->kind) {
            Proposal::ADD => 'запомнить новый факт',
            Proposal::CHANGE => sprintf('изменить факт #%d', (int) $proposal->factId),
            Proposal::END => sprintf('отметить, что факт #%d больше не верен', (int) $proposal->factId),
            Proposal::FORGET => sprintf('забыть факт #%d насовсем', (int) $proposal->factId),
            Proposal::PROFILE => 'заменить профиль владельца',
            Proposal::SUMMARY => 'заменить сводку текущих дел',
            default => $proposal->kind,
        };
        return sprintf(
            "Память: предложение №%d — %s\nГде: %s\n\n%s\n\nПричина: %s",
            $proposal->id,
            $what,
            $proposal->scope,
            SecretMasker::mask($proposal->text),
            SecretMasker::mask($proposal->reason),
        );
    }
}
