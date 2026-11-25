<?php

declare(strict_types=1);

namespace OpenCCK\App\Agent;

use Mercurius\Messenger\Button;
use Mercurius\Messenger\CallbackHandler;
use Mercurius\Messenger\Channel;
use Mercurius\Messenger\IncomingCallback;
use Mercurius\Messenger\OutgoingMessage;
use OpenCCK\App\Agent\Tool\Guard\SecretMasker;
use OpenCCK\App\Service\OwnerDirectory;
use OpenCCK\Domain\Entity\Member;
use OpenCCK\Domain\Repository\MemberRepository;
use Mercurius\Memory\App\Audience;
use Mercurius\Memory\App\Proposals;
use Mercurius\Memory\Domain\Fact;
use Mercurius\Memory\Domain\MemoryStore;
use Mercurius\Memory\Domain\Proposal;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

use function Mercurius\I18n\t;

/**
 * A memory proposal is a card in the owner's chat: accept or reject, decided once. The run that proposed does not
 * wait; the change applies when the owner presses.
 *
 * spec: brief/memory.md#как-сделано-области-и-факты — the owner approves what goes into memory
 */
final class MemoryCards implements CallbackHandler {
    public const PREFIX = 'mp';
    /** the button that forgets a fact the agent wrote itself */
    public const FORGET = 'mf';

    /** @var (\Closure(int, string): int)|null in how many days the person said a text in their own messages */
    private ?\Closure $days = null;
    /** @var (\Closure(Member, string): void)|null lets sleep make a kind of edits by itself from now on */
    private ?\Closure $always = null;

    /**
     * The third button of sleep's cards, «Always do so»: accepts the card and gives sleep the standing permission for
     * its kind of edit.
     *
     * spec: brief/memory.md#постоянное-разрешение
     * @param \Closure(Member, string): void $always the owner and the kind (SleepEdit::COPY, MERGE)
     */
    public function useAlways(\Closure $always): void {
        $this->always = $always;
    }

    /** The kind of standing permission a card of sleep may give; null — the card is not sleep's. */
    private static function always(Proposal $proposal): ?string {
        if ($proposal->provenance !== Proposal::SLEEP) {
            return null;
        }
        return match ($proposal->kind) {
            Proposal::FORGET => \Mercurius\Memory\Domain\SleepEdit::COPY,
            Proposal::MERGE => \Mercurius\Memory\Domain\SleepEdit::MERGE,
            default => null,
        };
    }

    public function __construct(
        private readonly Proposals $proposals,
        private readonly MemoryStore $store,
        private readonly Channel $channel,
        private readonly OwnerDirectory|string $ownerChatId,
        private readonly MemberRepository $principals,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {}

    /**
     * spec: brief/memory.md#консолидация-dreaming — the card says on how many days the person said it: code tells what holds
     *       from what was said once
     *
     * @param \Closure(int, string): int $days the person and the text => the number of days
     */
    public function useCorroboration(\Closure $days): void {
        $this->days = $days;
    }

    /**
     * spec: brief/participants.md#несколько-пользователей — a fact of a participant's own memory is theirs to approve: the card goes
     * to their private chat; everything else goes to the owner
     */
    public function show(Proposal $proposal): bool {
        $chatId = OwnerDirectory::chat($this->ownerChatId);
        // spec: brief/memory.md#постоянное-разрешение — what sleep found, in a participant's memory too, goes to the owner
        $who = $proposal->provenance === Proposal::SLEEP ? null : $this->ownerOf($proposal);
        if ($who !== null) {
            $chatId = $this->principals->externalId($who, $this->channel->name()) ?? OwnerDirectory::chat($this->ownerChatId);
        }
        // spec: brief/memory.md#запись-сведения-о-людях-и-забывание — a fact the agent wrote itself comes as a notice the owner
        //       may undo with one press; anything else waits for the owner's decision
        $written = $proposal->kind === Proposal::ADD && $proposal->status === Proposal::APPROVED && $proposal->factId !== null;
        $result = $this->channel->send(new OutgoingMessage($chatId, $written ? t('agent.memory_cards.written') . "\n" . $this->card($proposal) : $this->card($proposal, true), buttons: [$written ? [
            new Button(t('agent.memory_cards.forget'), sprintf('%s:%d:f', self::FORGET, $proposal->factId)),
        ] : [
            new Button(t('agent.memory_cards.prinyat'), sprintf('%s:%d:a', self::PREFIX, $proposal->id)),
            new Button(t('agent.memory_cards.otklonit'), sprintf('%s:%d:r', self::PREFIX, $proposal->id)),
            ...($this->always !== null && self::always($proposal) !== null ? [new Button(t('agent.memory_cards.always'), sprintf('%s:%d:w', self::PREFIX, $proposal->id))] : []),
        ]]));
        if (!$result->success || $result->messageId === null) {
            $this->logger->warning('Memory card was not delivered', ['proposal' => $proposal->id]);
            return false;
        }
        $this->store->setCard($proposal->id, $chatId, $result->messageId);
        return true;
    }

    public function handleCallback(IncomingCallback $callback): void {
        if (preg_match('/^mf:(\d+):f$/', $callback->data, $forget) === 1) {
            $this->forgetWritten($callback, (int) $forget[1]);
            return;
        }
        if (preg_match('/^mp:(\d+):([arw])$/', $callback->data, $match) !== 1) {
            $this->channel->answerCallback($callback->callbackId);
            return;
        }
        $approve = $match[2] !== 'r';
        $presser = $this->principals->findByIdentity($callback->channel, $callback->userId);
        $card = $this->store->proposal((int) $match[1]);
        $kind = $card === null ? null : self::always($card);
        // a standing permission is the owner's to give, and only on a card of sleep
        if ($match[2] === 'w' && ($presser === null || !$presser->isOwner() || $kind === null || $this->always === null)) {
            $this->channel->answerCallback($callback->callbackId, t('agent.callback_router.eto_mozhet_tolko_vladelets'), true);
            return;
        }
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
        $outcome = $decision->outcome;
        if ($match[2] === 'w') {
            ($this->always)($presser, $kind);
            $outcome .= "\n" . sprintf(t('agent.memory_cards.always_set'), $kind);
        }
        $this->channel->answerCallback($callback->callbackId, $decision->outcome);
        if ($callback->messageId !== null) {
            $this->channel->edit($callback->chatId, $callback->messageId, $this->card($decision->proposal) . "\n\n" . $outcome);
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

    /** The owner undoes a fact the agent wrote: it is forgotten, as by /forget. */
    private function forgetWritten(IncomingCallback $callback, int $factId): void {
        $presser = $this->principals->findByIdentity($callback->channel, $callback->userId);
        $scope = $this->store->fact($factId)?->scope;
        // the owner forgets any fact; a participant — one of their own memory
        $mine = $presser !== null && $scope === \Mercurius\Memory\App\Audience::principal($presser->id);
        if ($presser === null || (!$presser->isOwner() && !$mine)) {
            $this->channel->answerCallback($callback->callbackId, t('agent.callback_router.eto_mozhet_tolko_vladelets'), true);
            return;
        }
        $done = $this->proposals->forget($factId);
        $this->channel->answerCallback($callback->callbackId, $done ? t('agent.memory_cards.forgotten') : t('agent.memory_cards.uzhe_resheno'));
        if ($done && $callback->messageId !== null) {
            $this->channel->edit($callback->chatId, $callback->messageId, t('agent.memory_cards.forgotten'));
        }
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
            Proposal::MERGE => sprintf(t('agent.memory_cards.merge'), (int) $proposal->factId, (int) $proposal->mergeWith),
            Proposal::PROFILE => t('agent.memory_cards.zamenit_profil_vladeltsa'),
            Proposal::SUMMARY => t('agent.memory_cards.zamenit_svodku_tekuschih_del'),
            default => $proposal->kind,
        };
        $text = SecretMasker::mask($proposal->text);
        if ($withOld && $proposal->kind === Proposal::CHANGE && $proposal->factId !== null) {
            $old = $this->store->fact($proposal->factId)->text ?? '';
            $text = sprintf(t('agent.memory_cards.bylo_stanet'), SecretMasker::mask($old), $text);
        }
        // spec: brief/memory.md#слияние — both facts as they are now, and the one text that replaces them
        if ($proposal->kind === Proposal::MERGE) {
            $keep = (int) $proposal->factId;
            $drop = (int) $proposal->mergeWith;
            $text = sprintf(
                t('agent.memory_cards.merge_was'),
                $keep,
                SecretMasker::mask($this->store->fact($keep)->text ?? '—'),
                $drop,
                SecretMasker::mask($this->store->fact($drop)->text ?? '—'),
                $text,
            );
        }
        return sprintf(
            t('agent.memory_cards.pamyat_predlozhenie_predlozhil_gde_budet'),
            $proposal->id,
            $what,
            $this->who($proposal->principalId),
            $this->place($proposal->scope),
            $text,
            SecretMasker::mask($proposal->reason),
        ) . ($proposal->aboutPerson ? "
" . t('agent.memory_cards.about_person') : '') . $this->origin($proposal);
    }

    /** Where the fact came from and on how many days the person said it — for a new or a changed fact. */
    private function origin(Proposal $proposal): string {
        $lines = '';
        // spec: brief/memory.md#запись-сведения-о-людях-и-забывание — how an existing fact served in answers, before it is changed or forgotten
        if ($proposal->factId !== null && in_array($proposal->kind, [Proposal::CHANGE, Proposal::END, Proposal::FORGET], true)) {
            $usage = $this->store->usage($proposal->factId);
            $lines .= "\n" . sprintf(t('agent.memory_cards.usage'), $usage['recalled'], $usage['helpful'], $usage['unhelpful']);
        }
        if (!in_array($proposal->kind, [Proposal::ADD, Proposal::CHANGE], true)) {
            return $lines;
        }
        if ($proposal->provenance !== null && $proposal->provenance !== '') {
            $lines .= "\n" . sprintf(t('agent.memory_cards.provenance'), t('memory.provenance.' . $proposal->provenance));
        }
        $person = $this->ownerOf($proposal) ?? $this->principals->owner()?->id;
        if ($this->days === null || $person === null) {
            return $lines;
        }
        try {
            $days = ($this->days)($person, $proposal->text);
        } catch (Throwable $e) {
            $this->logger->warning('Repeats of a memory proposal were not counted', ['proposal' => $proposal->id, 'error' => $e::class]);
            return $lines;
        }
        $key = $days >= 2 ? 'agent.memory_cards.days_many' : ($days === 1 ? 'agent.memory_cards.days_one' : 'agent.memory_cards.days_none');
        return $lines . "\n" . sprintf(t($key), $days, \Mercurius\Memory\App\Corroboration::WINDOW_DAYS);
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
