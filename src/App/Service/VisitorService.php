<?php

declare(strict_types=1);

namespace OpenCCK\App\Service;

use Mercurius\Messenger\Button;
use Mercurius\Messenger\CallbackHandler;
use Mercurius\Messenger\Channel;
use Mercurius\Messenger\IncomingCallback;
use Mercurius\Messenger\IncomingMessage;
use Mercurius\Messenger\OutgoingMessage;
use OpenCCK\App\Agent\Tool\SecretMasker;
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
 * spec: BRIEF.md#участники-люди-и-агенты — an unknown writer is shown to the owner, who decides
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

    public function __construct(
        private readonly PrincipalRepository $principals,
        private readonly Channel $channel,
        private readonly string $ownerChatId,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {}

    /** Creates a pending visitor for a stranger and shows them to the owner once. */
    public function admit(IncomingMessage $message): Principal {
        $visitor = $this->principals->createVisitor($message->channel, $message->userId, $message->displayName ?? $message->userName);
        $text = trim($message->text);
        $preview = mb_strlen($text) > self::PREVIEW ? mb_substr($text, 0, self::PREVIEW) . '…' : $text;
        $card = sprintf(
            t('service.visitor.novyy_sobesednik_napisal_chto_emu'),
            self::who($visitor, $message->userName, $message->userId),
            SecretMasker::mask($preview === '' ? t('service.visitor.ne_tekst') : $preview),
        );
        $result = $this->channel->send(new OutgoingMessage($this->ownerChatId, $card, buttons: [[
            new Button(t('service.visitor.razgovor'), sprintf('pv:%d:t', $visitor->id)),
            new Button(t('service.visitor.polzovatel'), sprintf('pv:%d:u', $visitor->id)),
            new Button(t('service.visitor.admin'), sprintf('pv:%d:a', $visitor->id)),
            new Button(t('service.visitor.otkazat'), sprintf('pv:%d:r', $visitor->id)),
        ]]));
        if (!$result->success) {
            $this->logger->warning('Visitor card was not delivered', ['visitor' => $visitor->id]);
        }
        $this->logger->notice('New visitor waits for the owner', ['visitor' => $visitor->id, 'channel' => $message->channel]);
        return $visitor;
    }

    public function handleCallback(IncomingCallback $callback): void {
        if (preg_match('/^pv:(\d+):([tuar])$/', $callback->data, $match) !== 1) {
            $this->channel->answerCallback($callback->callbackId);
            return;
        }
        [$role, $status, $outcome] = match ($match[2]) {
            't' => [Role::Visitor, PrincipalStatus::Active, t('service.visitor.razgovor_razreshen')],
            'u' => [Role::User, PrincipalStatus::Active, t('service.visitor.naznachen_polzovatelem')],
            'a' => [Role::Admin, PrincipalStatus::Active, t('service.visitor.naznachen_administratorom')],
            default => [Role::Visitor, PrincipalStatus::Rejected, t('service.visitor.otkazano')],
        };
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
            $this->tell($decided, $callback->channel, match ($role) {
                Role::User => \Mercurius\I18n\t(self::LET_IN_USER),
                Role::Admin => \Mercurius\I18n\t(self::LET_IN_ADMIN),
                default => \Mercurius\I18n\t(self::LET_IN_TALK),
            });
        }
        $this->logger->info('Visitor decided', ['visitor' => $decided->id, 'role' => $role->value, 'status' => $status->value]);
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
