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

use function Mercurius\I18n\t;

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

    /**
     * spec: BRIEF.md#несколько-пользователей — a fact of a participant's own memory is theirs to approve: the card goes
     * to their private chat; everything else goes to the owner
     */
    public function show(Proposal $proposal): bool {
        $chatId = $this->ownerChatId;
        $who = $this->ownerOf($proposal);
        if ($who !== null) {
            $chatId = $this->principals->externalId($who, 'telegram') ?? $this->ownerChatId;
        }
        $result = $this->channel->send(new OutgoingMessage($chatId, $this->card($proposal, true), buttons: [[
            new Button(t('agent.memory_cards.prinyat'), sprintf('%s:%d:a', self::PREFIX, $proposal->id)),
            new Button(t('agent.memory_cards.otklonit'), sprintf('%s:%d:r', self::PREFIX, $proposal->id)),
        ]]));
        if (!$result->success || $result->messageId === null) {
            $this->logger->warning('Memory card was not delivered', ['proposal' => $proposal->id]);
            return false;
        }
        $this->store->setCard($proposal->id, $chatId, $result->messageId);
        return true;
    }

    public function handleCallback(IncomingCallback $callback): void {
        if (preg_match('/^mp:(\d+):([ar])$/', $callback->data, $match) !== 1) {
            $this->channel->answerCallback($callback->callbackId);
            return;
        }
        $approve = $match[2] === 'a';
        if (!$this->mayDecide($callback, (int) $match[1])) {
            $this->channel->answerCallback($callback->callbackId, t('agent.callback_router.eto_mozhet_tolko_vladelets'), true);
            return;
        }
        try {
            $decision = $this->proposals->decide((int) $match[1], $approve);
        } catch (Throwable $e) {
            $this->logger->error('Memory proposal could not be applied', ['proposal' => (int) $match[1], 'error' => $e::class]);
            $this->channel->answerCallback($callback->callbackId, t('agent.memory_cards.ne_poluchilos_izmenit_pamyat_nazhmite'), true);
            return;
        }
        if ($decision === null) {
            $this->channel->answerCallback($callback->callbackId, t('agent.memory_cards.uzhe_resheno'));
            return;
        }
        $this->channel->answerCallback($callback->callbackId, $decision->outcome);
        if ($callback->messageId !== null) {
            $this->channel->edit($callback->chatId, $callback->messageId, $this->card($decision->proposal) . "\n\n" . $decision->outcome);
        }
        $this->logger->info('Memory proposal decided', ['proposal' => $decision->proposal->id, 'kind' => $decision->proposal->kind, 'approved' => $approve, 'applied' => $decision->applied]);
    }

    /** The participant whose own memory a proposal is about; null — the owner's or a chat's memory. */
    private function ownerOf(Proposal $proposal): ?int {
        if (preg_match('~^principal:(\d+)$~', $proposal->scope, $m) !== 1) {
            return null;
        }
        $principal = $this->principals->find((int) $m[1]);
        return $principal === null || $principal->isOwner() ? null : $principal->id;
    }

    /** The owner decides any proposal; a participant only one of their own memory. */
    private function mayDecide(IncomingCallback $callback, int $id): bool {
        $presser = $this->principals->findByIdentity($callback->channel, $callback->userId);
        if ($presser !== null && $presser->isOwner()) {
            return true;
        }
        $proposal = $this->store->proposal($id);
        return $presser !== null && $proposal !== null && $this->ownerOf($proposal) === $presser->id;
    }

    /**
     * Drops the text of a forgotten fact from the cards that still show it.
     * @param list<array{string, string}> $cards
     */
    public function forgotten(Fact $fact, array $cards): void {
        foreach ($cards as [$chatId, $messageId]) {
            $this->channel->edit($chatId, $messageId, sprintf(t('agent.memory_cards.pamyat_fakt_zabyt_vladeltsem'), $fact->id));
        }
    }

    /**
     * What the owner needs to decide: who asks, where the fact will be read, the text (old and new for a change), and
     * the reason marked as the agent's words — it may echo a page or a group member.
     */
    private function card(Proposal $proposal, bool $withOld = false): string {
        $what = match ($proposal->kind) {
            Proposal::ADD => t('agent.memory_cards.zapomnit_novyy_fakt'),
            Proposal::CHANGE => sprintf(t('agent.memory_cards.izmenit_fakt'), (int) $proposal->factId),
            Proposal::END => sprintf(t('agent.memory_cards.otmetit_chto_fakt_bolshe_ne'), (int) $proposal->factId),
            Proposal::FORGET => sprintf(t('agent.memory_cards.zabyt_fakt_nasovsem'), (int) $proposal->factId),
            Proposal::PROFILE => t('agent.memory_cards.zamenit_profil_vladeltsa'),
            Proposal::SUMMARY => t('agent.memory_cards.zamenit_svodku_tekuschih_del'),
            default => $proposal->kind,
        };
        $text = SecretMasker::mask($proposal->text);
        if ($withOld && $proposal->kind === Proposal::CHANGE && $proposal->factId !== null) {
            $old = $this->store->fact($proposal->factId)->text ?? '';
            $text = sprintf(t('agent.memory_cards.bylo_stanet'), SecretMasker::mask($old), $text);
        }
        return sprintf(
            t('agent.memory_cards.pamyat_predlozhenie_predlozhil_gde_budet'),
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
            return t('agent.memory_cards.agent');
        }
        $principal = $this->principals->find($principalId);
        if ($principal === null) {
            return sprintf(t('agent.memory_cards.uchastnik'), $principalId);
        }
        return $principal->isOwner() ? t('agent.memory_cards.agent_v_razgovore_s_vami') : sprintf(t('agent.memory_cards.agent_v_razgovore_s'), $principal->displayName ?? ('#' . $principalId));
    }

    private function place(string $scope): string {
        if ($scope === Audience::OWNER) {
            return t('agent.memory_cards.vasha_pamyat_tolko_vam_v');
        }
        if (str_starts_with($scope, 'principal:')) {
            $id = (int) substr($scope, strlen('principal:'));
            $name = $this->principals->find($id)->displayName ?? ('#' . $id);
            return sprintf(t('agent.memory_cards.lichnaya_pamyat_uchastnika'), $name);
        }
        $chat = explode(':', $scope, 3);
        return sprintf(t('agent.memory_cards.chat_vsem_ego_uchastnikam'), $chat[2] ?? $scope, $chat[1] ?? '');
    }
}
