<?php

declare(strict_types=1);

namespace OpenCCK\App\Peer;

use Mercurius\Messenger\Button;
use Mercurius\Messenger\CallbackHandler;
use Mercurius\Messenger\Channel;
use Mercurius\Messenger\IncomingCallback;
use Mercurius\Messenger\OutgoingMessage;
use Mercurius\Messenger\SendErrorKind;
use Mercurius\Messenger\SendResult;
use OpenCCK\Domain\Entity\Role;
use OpenCCK\Domain\Peer\AgentRequest;
use OpenCCK\Domain\Repository\PeerRepository;
use OpenCCK\Domain\Repository\PrincipalRepository;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

use function Mercurius\I18n\t;

/**
 * An agent that came with the entrance token and proved its key, but is not let in, is shown to the owner the way a
 * stranger writing to the bot is: one card per key with what it called itself, its address, how it came and the
 * fingerprint; the owner makes it a user or an admin, or refuses. Until then, and after a refusal, it is turned away;
 * a refused key is not shown again. The name and the address are its own words: cleaned, and a name already taken by a
 * known agent gets a tail of the key, so a claimed name never takes over someone else's agent.
 *
 * spec: BRIEF.md#незнакомый-агент-стучится-решение-владельца-2026-11-17
 * ref: references/hermes-agent/plugins/platforms/a2a/security.py@e96221fa:L121-L129 — hermes refuses a peer outside
 *      the allow-list it reads from the config; here the owner adds to the list with a button when the peer knocks
 * ref: references/hermes-agent/gateway/pairing.py@e96221fa:L29-L37 — a stranger waits for the owner's approval; as
 *      VisitorService, a button in the owner's chat instead of a one-time code
 */
final class AgentRequests implements CallbackHandler {
    public const PREFIX = 'pa';

    public function __construct(
        private readonly PeerRepository $peers,
        private readonly PrincipalRepository $principals,
        private readonly Channel $channel,
        private readonly string $ownerChatId,
        private readonly LoggerInterface $logger = new NullLogger(),
        /** at most this many agents wait for the owner at once; the rest are told to come later and not shown */
        private readonly int $maxPending = 20,
        /** @var list<float> pauses before the next tries of a card the messenger did not take */
        private readonly array $retryDelays = [2.0, 4.0],
        /** the card and the decision go into the owner's conversation, so the agent knows what the owner asks about */
        private readonly ?\OpenCCK\Domain\Repository\ConversationRepository $conversations = null,
        private readonly ?\OpenCCK\Domain\Repository\TranscriptRepository $transcript = null,
    ) {}

    /** A name an agent goes by here: latin letters, digits, - and _, up to 40; agent-<key> when nothing is left. */
    public static function nameFrom(string $claimed, string $fingerprint): string {
        $name = trim((string) preg_replace('~[^a-z0-9_-]+~', '-', strtolower($claimed)), '-_');
        $name = trim(substr($name, 0, 40), '-_');
        return preg_match(PeerNetwork::NAME_PATTERN, $name) === 1 ? $name : 'agent-' . self::tail($fingerprint);
    }

    /** The first label of the host of an address: what an instance calls itself to a neighbour. */
    public static function nameOfUrl(string $url): string {
        $host = parse_url($url, PHP_URL_HOST);
        return is_string($host) ? explode('.', $host)[0] : '';
    }

    /**
     * An unknown key at the entrance: the request is kept and shown to the owner once.
     *
     * @return string why the agent is turned away, for the agent
     */
    public function knock(string $fingerprint, string $claimedName, string $claimedUrl, string $transport): string {
        $request = $this->peers->requestAccess($fingerprint, self::nameFrom($claimedName, $fingerprint), self::url($claimedUrl), $transport === 'ws' ? 'ws' : 'http', $this->maxPending);
        if ($request === null) {
            $this->logger->warning('Too many agents wait for the owner', ['fingerprint' => $fingerprint]);
            return 'unknown agent ' . $fingerprint . ': too many agents wait for the decision of this agent\'s owner, come later';
        }
        [$kept, $fresh] = $request;
        if ($kept->status === AgentRequest::REJECTED) {
            return 'agent ' . $fingerprint . ' is refused by the owner of this agent';
        }
        if ($kept->status === AgentRequest::ALLOWED) {
            // let in once and revoked since: the owner lets it in again with /peer allow
            return 'unknown agent ' . $fingerprint . ': its owner lets it in with /peer allow';
        }
        if ($fresh) {
            $this->show($kept);
        }
        return 'unknown agent ' . $fingerprint . ': waits for the decision of this agent\'s owner';
    }

    public function handleCallback(IncomingCallback $callback): void {
        if (preg_match('/^pa:(\d+):([uar])$/', $callback->data, $match) !== 1) {
            $this->channel->answerCallback($callback->callbackId);
            return;
        }
        $role = match ($match[2]) {
            'u' => Role::User,
            'a' => Role::Admin,
            default => null,
        };
        $decided = $this->peers->decideRequest((int) $match[1], $role === null ? AgentRequest::REJECTED : AgentRequest::ALLOWED);
        if ($decided === null) {
            $this->channel->answerCallback($callback->callbackId, t('peer.agent_requests.decided'));
            return;
        }
        if ($role === null) {
            $outcome = sprintf(t('peer.agent_requests.rejected'), $decided->name, $decided->fingerprint);
        } else {
            $name = $this->freeName($decided->name, $decided->fingerprint);
            $agent = $this->principals->createAgent($name, $role);
            $this->peers->allowKey($agent->id, $name, $decided->fingerprint);
            $outcome = sprintf(t('peer.agent_requests.allowed'), $name, $decided->fingerprint, $role->value);
        }
        $this->channel->answerCallback($callback->callbackId, mb_substr($outcome, 0, 190));
        if ($callback->messageId !== null) {
            $this->channel->edit($callback->chatId, $callback->messageId, $outcome);
        }
        $this->note(sprintf(t('peer.agent_requests.note_decided'), $outcome));
        $this->logger->info('Agent request decided', ['request' => $decided->id, 'role' => $role === null ? 'refused' : $role->value]);
    }

    private function show(AgentRequest $request): void {
        $card = sprintf(
            t('peer.agent_requests.card'),
            t('peer.agent_requests.' . $request->transport),
            $request->name,
            $request->url === '' ? t('peer.agent_requests.no_url') : $request->url,
            $request->fingerprint,
        );
        // the address is the agent's own words: no preview, or Telegram would put its page into the owner's card
        $result = $this->send(new OutgoingMessage($this->ownerChatId, $card, disableLinkPreview: true, buttons: [[
            new Button(t('peer.agent_requests.user'), sprintf('%s:%d:u', self::PREFIX, $request->id)),
            new Button(t('peer.agent_requests.admin'), sprintf('%s:%d:a', self::PREFIX, $request->id)),
            new Button(t('peer.agent_requests.reject'), sprintf('%s:%d:r', self::PREFIX, $request->id)),
        ]]));
        if (!$result->success) {
            // nobody would ever decide: forget it, the next knock shows it again
            $this->peers->dropRequest($request->id);
            $this->logger->warning('Agent request card was not delivered', ['request' => $request->id]);
            return;
        }
        $this->logger->notice('Unknown agent waits for the owner', ['request' => $request->id, 'fingerprint' => $request->fingerprint]);
        // the name and the address in the card are the agent's own words: data, not instructions
        $this->note(\OpenCCK\App\Agent\Tool\UntrustedWrapper::wrap('agent-request', sprintf(t('peer.agent_requests.note_card'), $card)));
    }

    /**
     * The owner's private conversation gets what the cards say: asked about a card, the agent knows it.
     *
     * spec: BRIEF.md#незнакомый-агент-стучится-решение-владельца-2026-11-17
     */
    private function note(string $text): void {
        if ($this->conversations === null || $this->transcript === null) {
            return;
        }
        $owner = $this->principals->findByIdentity($this->channel->name(), $this->ownerChatId);
        if ($owner === null) {
            return;
        }
        try {
            $conversation = $this->conversations->openConversation($owner->id, $this->channel->name(), $this->ownerChatId);
            $this->transcript->appendUser($conversation, null, $text);
        } catch (\Throwable $e) {
            $this->logger->warning('Agent request note was not kept', ['error' => $e::class]);
        }
    }

    /**
     * ref: references/hermes-agent/gateway/platforms/base.py@e96221fa:L3496-L3560 (_send_with_retry) — as VisitorService
     */
    private function send(OutgoingMessage $message): SendResult {
        $result = $this->channel->send($message);
        foreach ($this->retryDelays as $pause) {
            if ($result->success || !in_array($result->errorKind, [SendErrorKind::Transient, SendErrorKind::RateLimited, SendErrorKind::Unknown], true)) {
                break;
            }
            \Amp\delay($result->retryAfter !== null && $result->retryAfter <= 60 ? (float) $result->retryAfter : $pause);
            $result = $this->channel->send($message);
        }
        return $result;
    }

    /** The claimed name, or with a tail of the key when a known agent has it. */
    private function freeName(string $name, string $fingerprint): string {
        $taken = array_column($this->peers->keys(), 'name');
        if (!in_array($name, $taken, true)) {
            return $name;
        }
        $tailed = rtrim(substr($name, 0, 31), '-_') . '-' . self::tail($fingerprint);
        return in_array($tailed, $taken, true) ? 'agent-' . substr(self::hex($fingerprint), 0, 32) : $tailed;
    }

    /** An http(s) address without spaces, up to 200 characters; '' otherwise. */
    private static function url(string $claimed): string {
        $url = trim($claimed);
        return strlen($url) <= 200 && preg_match('~^https?://[^\s<>"\'`]+$~', $url) === 1 && filter_var($url, FILTER_VALIDATE_URL) !== false ? $url : '';
    }

    private static function hex(string $fingerprint): string {
        return str_starts_with($fingerprint, 'SHA256:') ? substr($fingerprint, 7) : $fingerprint;
    }

    private static function tail(string $fingerprint): string {
        return substr(self::hex($fingerprint), 0, 8);
    }
}
