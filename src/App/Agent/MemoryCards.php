<?php

declare(strict_types=1);

namespace OpenCCK\App\Agent;

use Mercurius\Messenger\Button;
use Mercurius\Messenger\CallbackHandler;
use Mercurius\Messenger\Channel;
use Mercurius\Messenger\IncomingCallback;
use Mercurius\Messenger\OutgoingMessage;
use OpenCCK\App\Agent\Tool\SecretMasker;
use OpenCCK\Domain\Repository\PrincipalRepository;
use OpenCCK\Memory\App\Audience;
use OpenCCK\Memory\App\Proposals;
use OpenCCK\Memory\Domain\Fact;
use OpenCCK\Memory\Domain\MemoryStore;
use OpenCCK\Memory\Domain\Proposal;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

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
        private readonly PrincipalRepository $principals,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {}

    public function show(Proposal $proposal): bool {
        $result = $this->channel->send(new OutgoingMessage($this->ownerChatId, $this->card($proposal, true), buttons: [[
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
        try {
            $decision = $this->proposals->decide((int) $match[1], $approve);
        } catch (Throwable $e) {
            $this->logger->error('Memory proposal could not be applied', ['proposal' => (int) $match[1], 'error' => $e::class]);
            $this->channel->answerCallback($callback->callbackId, 'Не получилось изменить память. Нажмите ещё раз.', true);
            return;
        }
        if ($decision === null) {
            $this->channel->answerCallback($callback->callbackId, 'Уже решено.');
            return;
        }
        $this->channel->answerCallback($callback->callbackId, $decision->outcome);
        if ($callback->messageId !== null) {
            $this->channel->edit($callback->chatId, $callback->messageId, $this->card($decision->proposal) . "\n\n" . $decision->outcome);
        }
        $this->logger->info('Memory proposal decided', ['proposal' => $decision->proposal->id, 'kind' => $decision->proposal->kind, 'approved' => $approve, 'applied' => $decision->applied]);
    }

    /**
     * Drops the text of a forgotten fact from the cards that still show it.
     * @param list<array{string, string}> $cards
     */
    public function forgotten(Fact $fact, array $cards): void {
        foreach ($cards as [$chatId, $messageId]) {
            $this->channel->edit($chatId, $messageId, sprintf('Память: факт #%d забыт владельцем.', $fact->id));
        }
    }

    /**
     * What the owner needs to decide: who asks, where the fact will be read, the text (old and new for a change), and
     * the reason marked as the agent's words — it may echo a page or a group member.
     */
    private function card(Proposal $proposal, bool $withOld = false): string {
        $what = match ($proposal->kind) {
            Proposal::ADD => 'запомнить новый факт',
            Proposal::CHANGE => sprintf('изменить факт #%d', (int) $proposal->factId),
            Proposal::END => sprintf('отметить, что факт #%d больше не верен', (int) $proposal->factId),
            Proposal::FORGET => sprintf('забыть факт #%d насовсем', (int) $proposal->factId),
            Proposal::PROFILE => 'заменить профиль владельца',
            Proposal::SUMMARY => 'заменить сводку текущих дел',
            default => $proposal->kind,
        };
        $text = SecretMasker::mask($proposal->text);
        if ($withOld && $proposal->kind === Proposal::CHANGE && $proposal->factId !== null) {
            $old = $this->store->fact($proposal->factId)->text ?? '';
            $text = sprintf("Было:\n%s\n\nСтанет:\n%s", SecretMasker::mask($old), $text);
        }
        return sprintf(
            "Память: предложение №%d — %s\nПредложил: %s\nГде будет видно: %s\n\n%s\n\nПричина (слова агента, проверьте сами): %s",
            $proposal->id,
            $what,
            $this->who($proposal->principalId),
            $this->place($proposal->scope),
            $text,
            SecretMasker::mask($proposal->reason),
        );
    }

    private function who(?int $principalId): string {
        if ($principalId === null) {
            return 'агент';
        }
        $principal = $this->principals->find($principalId);
        if ($principal === null) {
            return sprintf('участник #%d', $principalId);
        }
        return $principal->isOwner() ? 'агент в разговоре с вами' : sprintf('агент в разговоре с «%s»', $principal->displayName ?? ('#' . $principalId));
    }

    private function place(string $scope): string {
        if ($scope === Audience::OWNER) {
            return 'ваша память: только вам в личном чате';
        }
        if (str_starts_with($scope, 'principal:')) {
            $id = (int) substr($scope, strlen('principal:'));
            $name = $this->principals->find($id)->displayName ?? ('#' . $id);
            return sprintf('личная память участника «%s»', $name);
        }
        $chat = explode(':', $scope, 3);
        return sprintf('чат %s (%s): всем его участникам', $chat[2] ?? $scope, $chat[1] ?? '');
    }
}
