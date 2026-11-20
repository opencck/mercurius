<?php

declare(strict_types=1);

namespace OpenCCK\App\Service;

use Mercurius\Messenger\Button;
use Mercurius\Messenger\CallbackHandler;
use Mercurius\Messenger\Channel;
use Mercurius\Messenger\IncomingCallback;
use Mercurius\Messenger\IncomingMessage;
use Mercurius\Messenger\OutgoingMessage;
use Mercurius\Messenger\SendErrorKind;
use Mercurius\Messenger\SendResult;
use OpenCCK\App\Agent\Tool\Guard\SecretMasker;
use OpenCCK\Domain\Entity\Power;
use OpenCCK\Domain\Entity\Principal;
use OpenCCK\Domain\Entity\PrincipalStatus;
use OpenCCK\Domain\Entity\Role;
use OpenCCK\Domain\Repository\PrincipalRepository;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

use function Mercurius\I18n\t;

/**
 * A stranger who writes becomes a pending visitor, and the owner gets one card: let them talk (no tools), make them a
 * user or an admin, or refuse. Until the owner decides, the stranger gets a short notice and the model is not called.
 * Once let in, the visitor is told so (decision of the owner 2026-11-16); a refusal is not announced.
 *
 * spec: brief/participants.md#участники-люди-и-агенты — an unknown writer is shown to the owner, who decides
 * ref: references/hermes-agent/gateway/pairing.py@e96221fa:L29-L37 — hermes lets a stranger in only after the owner
 *      approves a one-time code in the server CLI; here the owner decides with a button in their own chat instead, so
 *      there is no code to leak or guess, and the decision is taken once in the database
 */
final class VisitorService implements CallbackHandler {
    public const WAITING = 'service.visitor_service.waiting';
    public const LET_IN_TALK = 'service.visitor_service.let_in_talk';
    public const LET_IN_USER = 'service.visitor_service.let_in_user';
    public const LET_IN_ADMIN = 'service.visitor_service.let_in_admin';
    /** The stranger's first message shown on the card, cut to this many characters. */
    private const PREVIEW = 500;
    /** Strangers of one group with their own card; the next ones share one summary card of the group. */
    public const GROUP_SINGLE_CARDS = 2;
    /** Names shown on a summary card; the rest is a count. */
    private const SUMMARY_NAMES = 30;

    /** @var array<string, int> strangers of a group shown on own cards, by chat */
    private array $singles = [];
    /** @var array<string, int> the summary of a group, by chat */
    private array $summaryOf = [];
    /** @var array<int, array{title: string, messageId: ?string, visitors: list<int>, names: list<string>}> */
    private array $summaries = [];
    private int $nextSummary = 1;

    public function __construct(
        private readonly PrincipalRepository $principals,
        private readonly Channel $channel,
        private readonly OwnerDirectory|string $ownerChatId,
        private readonly LoggerInterface $logger = new NullLogger(),
        /** @var list<float> pauses before the next tries of a card the messenger did not take */
        private readonly array $retryDelays = [2.0, 4.0],
    ) {}

    /**
     * Creates a pending visitor for a stranger and shows them to the owner once.
     *
     * @param string|null $group the title of the group the stranger wrote in; null — a private chat
     * @param string|null $groupChat the chat of that group: after a few strangers of one group the next ones share a
     *                               summary card (spec: brief/channels.md#группа — one card of strangers in a big group)
     */
    public function admit(IncomingMessage $message, ?string $group = null, ?string $groupChat = null): Principal {
        $visitor = $this->principals->createVisitor($message->channel, $message->userId, $message->displayName ?? $message->userName);
        if ($group !== null && $groupChat !== null) {
            $shown = $this->singles[$groupChat] ?? 0;
            if ($shown >= self::GROUP_SINGLE_CARDS) {
                $this->summarize($groupChat, $group, $visitor, $message);
                return $visitor;
            }
            $this->singles[$groupChat] = $shown + 1;
        }
        $text = trim($message->text);
        $preview = mb_strlen($text) > self::PREVIEW ? mb_substr($text, 0, self::PREVIEW) . '…' : $text;
        $who = self::who($visitor, $message->userName, $message->userId);
        $said = SecretMasker::mask($preview === '' ? t('service.visitor.ne_tekst') : $preview);
        $card = $group === null
            ? sprintf(t('service.visitor.novyy_sobesednik_napisal_chto_emu'), $who, $said)
            : sprintf(t('service.visitor.in_group'), $group, $who, $said);
        // the stranger's words may carry a link: no preview of it in the owner's card
        // spec: brief/participants.md#свои-роли — the card offers the roles in their order
        $buttons = array_map(static fn(Role $role): Button => new Button(RoleCatalog::label($role), sprintf('pv:%d', $visitor->id) . RoleCatalog::code($role)), Role::assignable());
        $buttons[] = new Button(t('service.visitor.otkazat'), sprintf('pv:%d:r', $visitor->id));
        $result = $this->send(new OutgoingMessage(OwnerDirectory::chat($this->ownerChatId), $card, disableLinkPreview: true, buttons: array_chunk($buttons, 3)));
        if (!$result->success) {
            $this->logger->warning('Visitor card was not delivered', ['visitor' => $visitor->id]);
        }
        // spec: brief/participants.md#свои-роли — those whose role may admit visitors get the card too
        foreach ($this->admitters($message->channel) as $chat) {
            $this->channel->send(new OutgoingMessage($chat, $card, disableLinkPreview: true, buttons: array_chunk($buttons, 3)));
        }
        $this->logger->notice('New visitor waits for the owner', ['visitor' => $visitor->id, 'channel' => $message->channel]);
        return $visitor;
    }

    /** @return list<string> the chats of the participants whose role may admit visitors, the owner left out */
    private function admitters(string $channel): array {
        $chats = [];
        foreach ($this->principals->all() as $p) {
            if (!$p->isOwner() && $p->status === PrincipalStatus::Active && $p->role->may(Power::Admit)) {
                $chat = $this->principals->externalId($p->id, $channel);
                if ($chat !== null) {
                    $chats[] = $chat;
                }
            }
        }
        return $chats;
    }

    /** Who pressed: the owner (null) or a participant whose role may admit visitors; false — nobody who may. */
    private function presser(IncomingCallback $callback): Principal|false|null {
        if ($callback->userId === OwnerDirectory::chat($this->ownerChatId)) {
            return null;
        }
        $who = $this->principals->findByIdentity($callback->channel, $callback->userId);
        return $who !== null && $who->status === PrincipalStatus::Active && ($who->isOwner() || $who->role->may(Power::Admit)) ? ($who->isOwner() ? null : $who) : false;
    }

    public function handleCallback(IncomingCallback $callback): void {
        $presser = $this->presser($callback);
        if ($presser === false || ($presser !== null && str_starts_with($callback->data, 'pv:g'))) {
            $this->channel->answerCallback($callback->callbackId, t('agent.callback_router.eto_mozhet_tolko_vladelets'), true);
            return;
        }
        if (preg_match('/^pv:g(\d+):([tr])$/', $callback->data, $match) === 1) {
            $this->decideSummary($callback, (int) $match[1], $match[2] === 't');
            return;
        }
        if (preg_match('/^pv:(\d+)(?::([tuar])|=([a-z0-9_-]{1,32}))$/', $callback->data, $match) !== 1) {
            $this->channel->answerCallback($callback->callbackId);
            return;
        }
        $named = ($match[3] ?? '') === '' ? null : Role::of($match[3]);
        if ($named !== null && !in_array($named, Role::assignable(), true)) {
            $this->channel->answerCallback($callback->callbackId, t('service.visitor.uzhe_resheno'));
            return;
        }
        [$role, $status, $outcome] = match (true) {
            $named !== null => [$named, PrincipalStatus::Active, sprintf(t('service.visitor.role_given'), RoleCatalog::label($named))],
            ($match[2] ?? '') === 't' => [Role::visitor(), PrincipalStatus::Active, t('service.visitor.razgovor_razreshen')],
            ($match[2] ?? '') === 'u' => [Role::user(), PrincipalStatus::Active, t('service.visitor.naznachen_polzovatelem')],
            ($match[2] ?? '') === 'a' => [Role::admin(), PrincipalStatus::Active, t('service.visitor.naznachen_administratorom')],
            default => [Role::visitor(), PrincipalStatus::Rejected, t('service.visitor.otkazano')],
        };
        // a participant who admits gives no role with a power they lack
        if ($presser !== null && $status === PrincipalStatus::Active && array_diff(array_map(static fn(Power $p): string => $p->value, $role->powers()), array_map(static fn(Power $p): string => $p->value, $presser->role->powers())) !== []) {
            $this->channel->answerCallback($callback->callbackId, t('tool.participants.above_own'), true);
            return;
        }
        $decided = $this->principals->decideVisitor((int) $match[1], $role, $status);
        if ($decided === null) {
            $this->channel->answerCallback($callback->callbackId, t('service.visitor.uzhe_resheno'));
            return;
        }
        $this->channel->answerCallback($callback->callbackId, $outcome);
        if ($callback->messageId !== null) {
            $this->channel->edit($callback->chatId, $callback->messageId, sprintf(t('service.visitor.sobesednik'), self::who($decided, null, null), $outcome));
        }
        if ($status === PrincipalStatus::Active) {
            $this->tell($decided, $callback->channel, match (true) {
                $role === Role::admin() => \Mercurius\I18n\t(self::LET_IN_ADMIN),
                $role->may(\OpenCCK\Domain\Entity\Power::Tools) => \Mercurius\I18n\t(self::LET_IN_USER),
                default => \Mercurius\I18n\t(self::LET_IN_TALK),
            });
        }
        $this->logger->info('Visitor decided', ['visitor' => $decided->id, 'role' => $role->value, 'status' => $status->value]);
    }

    /** Adds a stranger to the summary card of the group: sent once, then edited. */
    private function summarize(string $chat, string $title, Principal $visitor, IncomingMessage $message): void {
        $id = $this->summaryOf[$chat] ?? null;
        if ($id === null) {
            $id = $this->nextSummary++;
            $this->summaryOf[$chat] = $id;
        }
        $summary = $this->summaries[$id] ?? ['title' => $title, 'messageId' => null, 'visitors' => [], 'names' => []];
        $summary['visitors'][] = $visitor->id;
        $summary['names'][] = self::who($visitor, $message->userName, null);
        $this->summaries[$id] = $summary;
        $names = array_slice($summary['names'], -self::SUMMARY_NAMES);
        $text = sprintf(t('service.visitor.summary'), $summary['title'], count($summary['visitors']), implode("\n", $names))
            . (count($summary['names']) > self::SUMMARY_NAMES ? "\n…" : '');
        $buttons = [[new Button(t('service.visitor.summary_talk'), sprintf('pv:g%d:t', $id)), new Button(t('service.visitor.summary_reject'), sprintf('pv:g%d:r', $id))]];
        if ($summary['messageId'] !== null && $this->channel->edit(OwnerDirectory::chat($this->ownerChatId), $summary['messageId'], $text, buttons: $buttons)->success) {
            return;
        }
        $result = $this->send(new OutgoingMessage(OwnerDirectory::chat($this->ownerChatId), $text, disableLinkPreview: true, buttons: $buttons));
        $summary['messageId'] = $result->messageId;
        $this->summaries[$id] = $summary;
        if (!$result->success) {
            $this->logger->warning('Visitor summary was not delivered', ['group' => $chat]);
        }
    }

    /** One press decides every stranger still waiting on the summary; a new summary starts for the next ones. */
    private function decideSummary(IncomingCallback $callback, int $id, bool $talk): void {
        $summary = $this->summaries[$id] ?? null;
        if ($summary === null) {
            $this->channel->answerCallback($callback->callbackId, t('service.visitor.summary_gone'), true);
            return;
        }
        unset($this->summaries[$id]);
        $chat = array_search($id, $this->summaryOf, true);
        if (is_string($chat)) {
            unset($this->summaryOf[$chat]);
        }
        $decided = 0;
        foreach ($summary['visitors'] as $visitorId) {
            $principal = $this->principals->decideVisitor($visitorId, Role::visitor(), $talk ? PrincipalStatus::Active : PrincipalStatus::Rejected);
            if ($principal !== null) {
                $decided++;
                if ($talk) {
                    $this->tell($principal, $callback->channel, \Mercurius\I18n\t(self::LET_IN_TALK));
                }
            }
        }
        $outcome = $talk ? t('service.visitor.razgovor_razreshen') : t('service.visitor.otkazano');
        $this->channel->answerCallback($callback->callbackId, $outcome);
        if ($callback->messageId !== null) {
            $this->channel->edit($callback->chatId, $callback->messageId, sprintf(t('service.visitor.summary_done'), $summary['title'], $decided, $outcome));
        }
        $this->logger->info('Visitor summary decided', ['visitors' => $decided, 'talk' => $talk]);
    }

    /**
     * The card must reach the owner: a person whose card is lost waits for good. A failure that may pass (a timeout, a
     * network error, a short flood) is tried again; a timeout may have been delivered after all, and a second card is
     * harmless — its buttons answer "already decided".
     *
     * ref: references/hermes-agent/gateway/platforms/base.py@e96221fa:L3496-L3560 (_send_with_retry) — two more tries
     *      after 2 and 4 seconds, retry_after of a flood honoured; hermes does not retry a timeout, a card is worth it
     */
    private function send(OutgoingMessage $message): SendResult {
        $result = $this->channel->send($message);
        foreach ($this->retryDelays as $attempt => $pause) {
            if ($result->success || !in_array($result->errorKind, [SendErrorKind::Transient, SendErrorKind::RateLimited, SendErrorKind::Unknown], true)) {
                break;
            }
            $this->logger->notice('Visitor card is sent again', ['attempt' => $attempt + 2, 'kind' => $result->errorKind->value]);
            \Amp\delay($result->retryAfter !== null && $result->retryAfter <= 60 ? (float) $result->retryAfter : $pause);
            $result = $this->channel->send($message);
        }
        return $result;
    }

    /** A private chat with a person has the id of their account (Telegram), so the message goes there. */
    private function tell(Principal $visitor, string $channel, string $text): void {
        $chatId = $this->principals->externalId($visitor->id, $channel);
        if ($chatId === null || !$this->channel->send(new OutgoingMessage($chatId, $text))->success) {
            $this->logger->warning('Visitor was not told about the decision', ['visitor' => $visitor->id]);
        }
    }

    private static function who(Principal $principal, ?string $userName, ?string $userId): string {
        return trim(sprintf(
            t('service.visitor.uchastnik'),
            $principal->displayName ?? t('service.visitor.bez_imeni'),
            $userName !== null ? ' @' . $userName : '',
            $userId !== null ? ', id ' . $userId : '',
            $principal->id,
        ));
    }
}
