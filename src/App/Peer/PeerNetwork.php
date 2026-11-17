<?php

declare(strict_types=1);

namespace OpenCCK\App\Peer;

use Closure;
use DomainException;
use Mercurius\Messenger\Channel;
use Mercurius\Messenger\OutgoingMessage;
use OpenCCK\App\Agent\Tool\SecretMasker;
use OpenCCK\App\Agent\Tool\UntrustedWrapper;
use OpenCCK\App\Vault\Vault;
use OpenCCK\Domain\Entity\Principal;
use OpenCCK\Domain\Entity\PrincipalKind;
use OpenCCK\Domain\Entity\Role;
use OpenCCK\Domain\Peer\A2aTask;
use OpenCCK\Domain\Peer\OutgoingTask;
use OpenCCK\Domain\Repository\ConversationRepository;
use OpenCCK\Domain\Repository\PeerRepository;
use OpenCCK\Domain\Repository\PrincipalRepository;
use OpenCCK\Domain\Repository\TranscriptRepository;
use Psr\Log\LoggerInterface;
use Throwable;

use function Mercurius\I18n\t;

/**
 * The agent's side of the network: the agents it lets in by the fingerprint of their key, the neighbours it connects to
 * (their key pinned from the card), tasks it gives them and the polling of their state; a result comes to the chat and
 * into the conversation as untrusted data.
 *
 * spec: BRIEF.md#сеть-агентов — remote delegation is always in the background; the reply of a neighbour is data
 */
final class PeerNetwork {
    public const NAME_PATTERN = '~^[a-z0-9][a-z0-9_-]{0,39}$~';
    private const POLL_BATCH = 10;

    public function __construct(
        private readonly PeerRepository $peers,
        private readonly PrincipalRepository $principals,
        private readonly Vault $vault,
        private readonly A2aClient $client,
        private readonly ConversationRepository $conversations,
        private readonly TranscriptRepository $transcript,
        private readonly Channel $channel,
        /** this instance in a chain */
        private readonly string $self,
        private readonly LoggerInterface $logger,
        private readonly AgentIdentity $identity,
        /** @var Closure(): ?Principal the owner reads the tokens of neighbours */
        private readonly Closure $owner,
    ) {}

    /** Lets an agent in by the fingerprint of its key; it becomes a participant with the role. */
    public function allow(string $name, string $fingerprint, Role $role): string {
        if (preg_match(self::NAME_PATTERN, $name) !== 1) {
            throw new DomainException(t('peer.peer_network.imya_agenta_latinitsa_tsifry_defis'));
        }
        if (preg_match('~^SHA256:[0-9a-f]{64}$~', $fingerprint) !== 1) {
            throw new DomainException(t('peer.peer_network.otpechatok_sha256_64_hex_ego'));
        }
        if (!in_array($role, [Role::User, Role::Admin], true)) {
            throw new DomainException(t('peer.peer_network.rol_agenta_user_ili_admin'));
        }
        $agent = $this->principals->createAgent($name, $role);
        $this->peers->allowKey($agent->id, $name, $fingerprint);
        return sprintf(t('peer.peer_network.agent_mozhet_podklyuchatsya_rol_prava'), $name, $fingerprint, $role->value);
    }

    public function revoke(string $name): bool {
        return $this->peers->revokeKey($name);
    }

    public function fingerprint(): string {
        return $this->identity->fingerprint();
    }

    /** A neighbour: its card is fetched, its public key pinned; the token of its entrance is a vault secret by name. */
    public function add(Principal $owner, string $name, string $url, string $secret): string {
        if (preg_match(self::NAME_PATTERN, $name) !== 1) {
            throw new DomainException(t('peer.peer_network.imya_soseda_latinitsa_tsifry_defis'));
        }
        if (preg_match('~^https://[^\s/]+(/[^\s]*)?$~', $url) !== 1) {
            throw new DomainException(t('peer.peer_network.adres_soseda_https'));
        }
        if ($this->vault->reveal($secret, $owner) === null) {
            throw new DomainException(sprintf(t('peer.peer_network.sekreta_net_secret_token_soseda'), $secret, $secret));
        }
        try {
            $card = $this->client->card($url);
        } catch (Throwable $e) {
            throw new DomainException(t('peer.peer_network.kartochku_soseda_ne_poluchit') . $e->getMessage());
        }
        $key = A2aClient::cardKey($card) ?? throw new DomainException(t('peer.peer_network.v_kartochke_soseda_net_klyucha'));
        $this->peers->addPeer($name, rtrim($url, '/'), $secret, $key, $card);
        $title = isset($card['name']) && is_string($card['name']) ? $card['name'] : '?';
        return sprintf(
            t('peer.peer_network.sosed_dobavlen_ego_klyuch_sverte'),
            $name,
            $title,
            rtrim($url, '/'),
            AgentIdentity::fingerprintOf($key) ?? '?',
            $this->identity->fingerprint(),
        );
    }

    /**
     * Gives a task to a neighbour and returns at once; the result comes later.
     *
     * @param list<string> $path the chain the current run came by (empty — from a person)
     */
    public function delegate(Principal $who, string $peerName, string $task, string $channel, string $chatId, int $depth, array $path): OutgoingTask {
        $peer = $this->peers->peer($peerName) ?? throw new DomainException(sprintf(t('peer.peer_network.soseda_net'), $peerName));
        if (!$who->isOwner() && $who->kind !== PrincipalKind::Agent) {
            throw new DomainException(t('peer.peer_network.otdavat_zadachi_sosedyam_mozhet_vladelet'));
        }
        $owner = ($this->owner)() ?? throw new DomainException(t('peer.peer_network.vladelets_ne_nayden'));
        $token = $this->vault->reveal($peer->secret, $owner) ?? throw new DomainException(sprintf(t('peer.peer_network.net_tokena_soseda_sekret'), $peer->secret));
        $remote = $this->client->send($peer, $token, 'msg-' . bin2hex(random_bytes(8)), SecretMasker::mask($task), $depth + 1, [...$path, $this->self]);
        $remoteId = isset($remote['id']) && is_string($remote['id']) ? $remote['id'] : throw new DomainException(t('peer.peer_network.sosed_ne_vernul_nomer_zadachi'));
        $state = A2aClient::state($remote);
        $out = $this->peers->addOutgoing($peerName, $remoteId, $who->id, $channel, $chatId, mb_substr($task, 0, 500), $state === '' ? A2aTask::SUBMITTED : $state);
        if (in_array($state, A2aTask::TERMINAL, true)) {
            $this->finish($out, $state, A2aClient::answer($remote));
        }
        return $out;
    }

    /** One pass over open outgoing tasks. */
    public function poll(): void {
        foreach ($this->peers->openOutgoing(self::POLL_BATCH) as $out) {
            $peer = $this->peers->peer($out->peer);
            $owner = ($this->owner)();
            $token = $peer === null || $owner === null ? null : $this->vault->reveal($peer->secret, $owner);
            if ($peer === null || $token === null) {
                $this->finish($out, A2aTask::FAILED, t('peer.peer_network.sosed_ili_ego_token_udaleny'));
                continue;
            }
            try {
                $task = $this->client->get($peer, $token, $out->remoteId);
            } catch (Throwable $e) {
                $this->logger->notice('A2A poll failed', ['task' => $out->id, 'error' => $e->getMessage()]);
                continue;
            }
            $state = A2aClient::state($task);
            if (in_array($state, A2aTask::TERMINAL, true)) {
                $this->finish($out, $state, A2aClient::answer($task));
            }
        }
    }

    private function finish(OutgoingTask $out, string $state, string $answer): void {
        $this->peers->finishOutgoing($out->id, $state, mb_substr($answer, 0, 20_000));
        $label = match ($state) {
            A2aTask::COMPLETED => t('peer.peer_network.otvet'),
            A2aTask::INPUT_REQUIRED => t('peer.peer_network.vopros'),
            A2aTask::REJECTED => t('peer.peer_network.otkaz'),
            A2aTask::CANCELED => t('peer.peer_network.otmeneno'),
            default => t('peer.peer_network.sboy'),
        };
        $text = sprintf(t('peer.peer_network.agent_zadacha'), $out->peer, $out->id, $out->remoteId, $label, $answer === '' ? t('peer.peer_network.bez_teksta') : $answer);
        if ($out->channel !== A2aServer::CHANNEL) {
            // A task given from a task of a neighbour has no chat: its result stays in that conversation.
            $this->channel->send(new OutgoingMessage($out->chatId, SecretMasker::mask(mb_substr($text, 0, 3800))));
        }
        // The next turn of the conversation sees the reply as data, not instructions.
        $conversation = $this->conversations->openConversation($out->principalId, $out->channel, $out->chatId);
        $this->transcript->appendUser($conversation, null, UntrustedWrapper::wrap('agent:' . $out->peer, sprintf(t('peer.peer_network.itog_zadachi_sosedu'), $out->id, $out->peer, $label, $answer)));
    }

    public function text(): string {
        $lines = [t('peer.peer_network.moy_klyuch') . $this->identity->fingerprint(), '', t('peer.peer_network.sosedi_ya_podklyuchayus_k_nim')];
        foreach ($this->peers->peers() as $peer) {
            $lines[] = sprintf(t('peer.peer_network.klyuch_token_vhoda_v_sekrete'), $peer->name, $peer->url, AgentIdentity::fingerprintOf($peer->publicKey) ?? '?', $peer->secret);
        }
        if (count($lines) === 3) {
            $lines[] = t('peer.peer_network.net_peer_add_imya_https');
        }
        $lines[] = '';
        $lines[] = t('peer.peer_network.agenty_kotoryh_ya_puskayu_po');
        $keys = $this->peers->keys();
        foreach ($keys as $k) {
            $lines[] = sprintf('• %s — %s%s', $k['name'], $k['fingerprint'], $k['revoked'] ? t('peer.peer_network.otozvan') : '');
        }
        if ($keys === []) {
            $lines[] = t('peer.peer_network.net_peer_allow_imya_otpechatok');
        }
        return implode("\n", $lines);
    }

    /** @return list<string> */
    public function names(): array {
        return array_map(static fn($p): string => $p->name, $this->peers->peers());
    }
}
