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
    /** pauses before the next try to open a kept connection that failed: 30 s, doubled, at most 5 minutes */
    private const RETRY_FIRST = 30.0;
    private const RETRY_MAX = 300.0;
    /** @var array<string, array{fails: int, next: float}> failed tries by neighbour */
    private array $retries = [];
    /** @var (Closure(OutgoingTask): void)|null told when a task ends; the A2A server continues a task that waited for it */
    private ?Closure $finished = null;

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
        /** the keys this agent may present to neighbours; null — the main key only */
        private readonly ?AgentKeys $keys = null,
        /** live connections with other agents, both ways */
        private readonly ?A2aLinks $links = null,
        /** files of an answer go into the sandbox of the one who gave the task; null — they are only sent to the chat */
        private readonly ?\OpenCCK\App\Agent\Sandbox\Sandbox $sandbox = null,
        /** @var (Closure(int): ?\OpenCCK\Domain\Agent\SandboxLimits)|null the sandbox limits of a participant */
        private readonly ?Closure $limitsOf = null,
    ) {}

    /**
     * spec: BRIEF.md#сеть-агентов — results go up the chain: a task of a neighbour that waited for this one goes on
     * @param Closure(OutgoingTask): void $finished
     */
    public function onFinished(Closure $finished): void {
        $this->finished = $finished;
    }

    /**
     * Opens a connection to the neighbour now (or finds a live one): tasks then go over it both ways.
     *
     * spec: BRIEF.md#подключения-агентов-решение-владельца-2026-11-17
     */
    public function open(string $peerName): string {
        $peer = $this->peers->peer($peerName) ?? throw new DomainException(sprintf(t('peer.peer_network.soseda_net'), $peerName));
        if (A2aClient::transport($peer) !== 'ws') {
            throw new DomainException(sprintf(t('peer.peer_network.no_socket'), $peerName));
        }
        $owner = ($this->owner)() ?? throw new DomainException(t('peer.peer_network.vladelets_ne_nayden'));
        $token = $this->vault->reveal($peer->secret, $owner) ?? throw new DomainException(sprintf(t('peer.peer_network.net_tokena_soseda_sekret'), $peer->secret));
        try {
            $this->client->connect($peer, $token);
        } catch (\RuntimeException $e) {
            throw new DomainException($e->getMessage());
        }
        // spec: BRIEF.md#подключения-агентов-решение-владельца-2026-11-17 — an opened connection is kept until it is closed
        $this->peers->setKeep($peerName, true);
        unset($this->retries[$peerName]);
        return sprintf(t('peer.peer_network.link_open'), $peerName);
    }

    /** Closes a connection; an outgoing one is no longer kept. */
    public function closeLink(int $id): bool {
        foreach ($this->links?->all() ?? [] as $link) {
            if ($link['id'] === $id && $link['direction'] === A2aLinks::OUT) {
                $this->peers->setKeep($link['name'], false);
                unset($this->retries[$link['name']]);
            }
        }
        return $this->links?->close($id) ?? false;
    }

    /**
     * Opens again the kept connections that dropped (the neighbour restarted, the network blinked, this agent was
     * deployed). A failed try waits 30 s, then twice as long, up to 5 minutes. A live connection is kept alive by
     * WebSocket pings on both sides.
     *
     * spec: BRIEF.md#подключения-агентов-решение-владельца-2026-11-17
     */
    public function keepAlive(): void {
        if ($this->links === null) {
            return;
        }
        $now = microtime(true);
        foreach ($this->peers->peers() as $peer) {
            if (!$peer->keep || A2aClient::transport($peer) !== 'ws') {
                continue;
            }
            if ($this->links->to(AgentIdentity::fingerprintOf($peer->publicKey) ?? '') !== null) {
                unset($this->retries[$peer->name]);
                continue;
            }
            $retry = $this->retries[$peer->name] ?? null;
            if ($retry !== null && $retry['next'] > $now) {
                continue;
            }
            try {
                $this->open($peer->name);
                $this->logger->info('Kept connection opened again', ['peer' => $peer->name]);
            } catch (\Throwable $e) {
                $fails = ($retry['fails'] ?? 0) + 1;
                $this->retries[$peer->name] = ['fails' => $fails, 'next' => $now + min(self::RETRY_MAX, self::RETRY_FIRST * (float) (2 ** ($fails - 1)))];
                $this->logger->notice('Kept connection did not open', ['peer' => $peer->name, 'fails' => $fails, 'error' => mb_substr($e->getMessage(), 0, 200)]);
            }
        }
    }

    /** The connections in both directions, as the owner and the agent see them. */
    public function linksText(): string {
        $all = $this->links?->all() ?? [];
        if ($all === []) {
            return t('peer.peer_network.no_links');
        }
        $lines = [];
        foreach ($all as $link) {
            $lines[] = sprintf(
                t($link['direction'] === A2aLinks::OUT ? 'peer.peer_network.link_out' : 'peer.peer_network.link_in'),
                $link['id'],
                $link['name'],
                $link['fingerprint'],
                (int) round((microtime(true) - $link['opened']) / 60.0),
                $link['requests'],
            );
        }
        return implode("\n", $lines);
    }

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

    /**
     * The key this agent presents to the neighbour from now on ('' — the main one); a new key is made. The neighbour's
     * owner lets the new fingerprint in with /peer allow.
     *
     * spec: BRIEF.md#вход-по-websocket-и-несколько-ключей-решение-владельца-2026-11-17
     */
    public function useKey(string $peer, string $key): string {
        $mine = $this->myKey($key);
        if (!$this->peers->setIdentity($peer, $key)) {
            throw new DomainException(sprintf(t('peer.peer_network.soseda_net'), $peer));
        }
        return sprintf(t('peer.peer_network.key_used'), $peer, $key === '' ? t('peer.peer_network.main_key') : $key, $mine->fingerprint());
    }

    /** A key of this agent by name, made when there is none: the agent makes them itself, the owner is not asked. */
    public function makeKey(string $key): string {
        return sprintf(t('peer.peer_network.key_ready'), $key, $this->myKey($key)->fingerprint());
    }

    private function myKey(string $key): AgentIdentity {
        if ($key === '') {
            return $this->identity;
        }
        return ($this->keys ?? throw new DomainException(t('peer.agent_keys.bad_name')))->get($key);
    }

    public function revoke(string $name): bool {
        return $this->peers->revokeKey($name);
    }

    public function fingerprint(): string {
        return $this->identity->fingerprint();
    }

    /** A neighbour: its card is fetched, its public key pinned; the token of its entrance is a vault secret by name. */
    public function add(Principal $owner, string $name, string $url, string $secret, string $key = ''): string {
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
        $publicKey = A2aClient::cardKey($card) ?? throw new DomainException(t('peer.peer_network.v_kartochke_soseda_net_klyucha'));
        $mine = $this->myKey($key);
        $this->peers->addPeer($name, rtrim($url, '/'), $secret, $publicKey, $card, $key);
        $title = isset($card['name']) && is_string($card['name']) ? $card['name'] : '?';
        return sprintf(
            t('peer.peer_network.sosed_dobavlen_ego_klyuch_sverte'),
            $name,
            $title,
            rtrim($url, '/'),
            AgentIdentity::fingerprintOf($publicKey) ?? '?',
            $mine->fingerprint(),
        );
    }

    /**
     * Gives a task to a neighbour and returns at once; the result comes later.
     *
     * @param list<string> $path the chain the current run came by (empty — from a person)
     * @param list<array{name: string, bytes: string}> $files
     */
    public function delegate(Principal $who, string $peerName, string $task, string $channel, string $chatId, int $depth, array $path, array $files = [], ?int $leaderRun = null): OutgoingTask {
        $peer = $this->peers->peer($peerName);
        // spec: BRIEF.md#подключения-агентов-решение-владельца-2026-11-17 — an agent that connected here is reached over its connection
        $link = $peer === null ? $this->links?->named($peerName) : null;
        if (!$who->isOwner() && $who->kind !== PrincipalKind::Agent) {
            throw new DomainException(t('peer.peer_network.otdavat_zadachi_sosedyam_mozhet_vladelet'));
        }
        $messageId = 'msg-' . bin2hex(random_bytes(8));
        $token = null;
        if ($link !== null) {
            try {
                $remote = A2aClient::taskOf(A2aClient::over($link, 'SendMessage', A2aClient::message($messageId, SecretMasker::mask($task), $depth + 1, [...$path, $this->self], $files)));
            } catch (\RuntimeException $e) {
                throw new DomainException($e->getMessage());
            }
        } elseif ($peer === null) {
            throw new DomainException(sprintf(t('peer.peer_network.soseda_net'), $peerName));
        } else {
            $owner = ($this->owner)() ?? throw new DomainException(t('peer.peer_network.vladelets_ne_nayden'));
            $token = $this->vault->reveal($peer->secret, $owner) ?? throw new DomainException(sprintf(t('peer.peer_network.net_tokena_soseda_sekret'), $peer->secret));
            $remote = $this->followed($peer, fn(\OpenCCK\Domain\Peer\Peer $p): array => $this->client->send($p, $token, $messageId, SecretMasker::mask($task), $depth + 1, [...$path, $this->self], null, $files));
        }
        $remoteId = isset($remote['id']) && is_string($remote['id']) ? $remote['id'] : throw new DomainException(t('peer.peer_network.sosed_ne_vernul_nomer_zadachi'));
        $state = A2aClient::state($remote);
        $out = $this->peers->addOutgoing($peerName, $remoteId, $who->id, $channel, $chatId, mb_substr($task, 0, 500), $state === '' ? A2aTask::SUBMITTED : $state, $leaderRun);
        if (in_array($state, A2aTask::TERMINAL, true)) {
            $this->spend($out, $remote);
            $this->finish($out, $state, A2aClient::answer($remote), $this->answerFiles($out, $remote, $link, $peer, $token));
        }
        return $out;
    }

    /** One pass over open outgoing tasks. */
    public function poll(): void {
        foreach ($this->peers->openOutgoing(self::POLL_BATCH) as $out) {
            $peer = $this->peers->peer($out->peer);
            $link = $peer === null ? $this->links?->named($out->peer) : null;
            if ($link !== null) {
                try {
                    $task = A2aClient::over($link, 'GetTask', ['id' => $out->remoteId]);
                } catch (Throwable $e) {
                    $this->logger->notice('A2A poll failed', ['task' => $out->id, 'error' => $e->getMessage()]);
                    continue;
                }
                $state = A2aClient::state($task);
                if (in_array($state, A2aTask::TERMINAL, true)) {
                    $this->spend($out, $task);
                    $this->finish($out, $state, A2aClient::answer($task), $this->answerFiles($out, $task, $link, null, null));
                }
                continue;
            }
            $owner = ($this->owner)();
            $token = $peer === null || $owner === null ? null : $this->vault->reveal($peer->secret, $owner);
            if ($peer === null || $token === null) {
                $this->finish($out, A2aTask::FAILED, t('peer.peer_network.sosed_ili_ego_token_udaleny'));
                continue;
            }
            try {
                $task = $this->followed($peer, fn(\OpenCCK\Domain\Peer\Peer $p): array => $this->client->get($p, $token, $out->remoteId));
            } catch (Throwable $e) {
                $this->logger->notice('A2A poll failed', ['task' => $out->id, 'error' => $e->getMessage()]);
                continue;
            }
            $state = A2aClient::state($task);
            if (in_array($state, A2aTask::TERMINAL, true)) {
                $this->spend($out, $task);
                $this->finish($out, $state, A2aClient::answer($task), $this->answerFiles($out, $task, null, $peer, $token));
            }
        }
    }

    /**
     * What the neighbour said it spent on the task (`metadata.mercurius` of the task: its run, tokens, USD): kept with
     * the task, so the leader's trace shows it in the same unit. A neighbour that says nothing spent unknown.
     *
     * spec: BRIEF.md#сеть-агентов — the spend of the follower in the leader's trace
     * @param array<array-key, mixed> $task
     */
    private function spend(OutgoingTask $out, array $task): void {
        $meta = isset($task['metadata']) && is_array($task['metadata']) && isset($task['metadata']['mercurius']) && is_array($task['metadata']['mercurius']) ? $task['metadata']['mercurius'] : [];
        $run = isset($meta['run']) && is_int($meta['run']) ? $meta['run'] : null;
        $tokens = isset($meta['tokens']) && is_int($meta['tokens']) ? max(0, $meta['tokens']) : 0;
        $usd = isset($meta['usd']) && (is_float($meta['usd']) || is_int($meta['usd'])) ? max(0.0, (float) $meta['usd']) : null;
        if ($run !== null || $tokens > 0 || $usd !== null) {
            $this->peers->recordRemoteSpend($out->id, $run, $tokens, $usd);
        }
    }

    /**
     * The files of the neighbour's answer: taken from it over the connection (GetFile) or by GET /a2a/files, or as bytes
     * in the answer. A file that does not come is skipped and logged.
     *
     * spec: BRIEF.md#между-агентами — the answer of a neighbour with files
     *
     * @param array<array-key, mixed> $task
     * @return list<array{name: string, bytes: string}>
     */
    private function answerFiles(OutgoingTask $out, array $task, ?A2aSocket $link, ?\OpenCCK\Domain\Peer\Peer $peer, #[\SensitiveParameter] ?string $token): array {
        $files = [];
        foreach (A2aClient::answerFiles($task) as $file) {
            try {
                if ($file['raw'] !== '') {
                    $bytes = base64_decode($file['raw'], true);
                    if ($bytes !== false && strlen($bytes) <= A2aFiles::FILE_MAX) {
                        $files[] = ['name' => $file['name'], 'bytes' => $bytes];
                    }
                } elseif ($link !== null) {
                    $got = A2aClient::over($link, 'GetFile', ['id' => $file['id']]);
                    $bytes = isset($got['raw']) && is_string($got['raw']) ? base64_decode($got['raw'], true) : false;
                    if ($bytes !== false) {
                        $files[] = ['name' => $file['name'], 'bytes' => $bytes];
                    }
                } elseif ($peer !== null && $token !== null) {
                    $files[] = $this->client->download($peer, $token, $file['id'], $file['name']);
                }
            } catch (Throwable $e) {
                $this->logger->notice('A2A answer file not taken', ['task' => $out->id, 'error' => $e->getMessage()]);
            }
        }
        return $files;
    }

    /**
     * A call to the neighbour; when it has moved to another transport, its card is read again and the call repeated
     * once. The card is taken only with the key pinned for the neighbour: a card with another key is someone else.
     *
     * @template T
     * @param Closure(\OpenCCK\Domain\Peer\Peer): T $call
     * @return T
     */
    private function followed(\OpenCCK\Domain\Peer\Peer $peer, Closure $call): mixed {
        try {
            return $call($peer);
        } catch (A2aMoved) {
            $card = $this->client->card($peer->url);
            if (A2aClient::cardKey($card) !== $peer->publicKey) {
                throw new DomainException(t('peer.a2a_client.otvet_podpisan_ne_klyuchom_etogo'));
            }
            $this->peers->addPeer($peer->name, $peer->url, $peer->secret, $peer->publicKey, $card, $peer->identity);
            $this->logger->info('Neighbour card read again', ['peer' => $peer->name, 'transport' => A2aClient::transport($this->peers->peer($peer->name) ?? $peer)]);
            return $call($this->peers->peer($peer->name) ?? $peer);
        }
    }

    /** @param list<array{name: string, bytes: string}> $files */
    private function finish(OutgoingTask $out, string $state, string $answer, array $files = []): void {
        $this->peers->finishOutgoing($out->id, $state, mb_substr($answer, 0, 20_000));
        $label = match ($state) {
            A2aTask::COMPLETED => t('peer.peer_network.otvet'),
            A2aTask::INPUT_REQUIRED => t('peer.peer_network.vopros'),
            A2aTask::REJECTED => t('peer.peer_network.otkaz'),
            A2aTask::CANCELED => t('peer.peer_network.otmeneno'),
            default => t('peer.peer_network.sboy'),
        };
        $text = sprintf(t('peer.peer_network.agent_zadacha'), $out->peer, $out->id, $out->remoteId, $label, $answer === '' ? t('peer.peer_network.bez_teksta') : $answer);
        $kept = $this->keepFiles($out, $files);
        if ($out->channel !== A2aServer::CHANNEL) {
            // A task given from a task of a neighbour has no chat: its result stays in that conversation.
            $this->channel->send(new OutgoingMessage($out->chatId, SecretMasker::mask(mb_substr($text, 0, 3800))));
            // spec: BRIEF.md#между-агентами — the files of the answer come to the chat as files of their kind
            foreach ($files as $file) {
                $this->channel->sendMedia($out->chatId, \OpenCCK\App\Agent\Tool\SendFileTool::kind($file['name'], null), $file['name'], $file['bytes']);
            }
        }
        if ($kept !== '') {
            $answer .= "\n\n" . $kept;
        }
        // The next turn of the conversation sees the reply as data, not instructions.
        $conversation = $this->conversations->openConversation($out->principalId, $out->channel, $out->chatId);
        $this->transcript->appendUser($conversation, null, UntrustedWrapper::wrap('agent:' . $out->peer, sprintf(t('peer.peer_network.itog_zadachi_sosedu'), $out->id, $out->peer, $label, $answer)));
        if ($this->finished !== null) {
            try {
                ($this->finished)(new OutgoingTask($out->id, $out->peer, $out->remoteId, $out->principalId, $out->channel, $out->chatId, $out->task, $state, $answer, $out->leaderRun));
            } catch (Throwable $e) {
                $this->logger->error('A2A chain not continued', ['task' => $out->id, 'error' => $e::class]);
            }
        }
    }

    public function text(): string {
        $lines = [t('peer.peer_network.moy_klyuch') . $this->identity->fingerprint(), '', t('peer.peer_network.sosedi_ya_podklyuchayus_k_nim')];
        foreach ($this->peers->peers() as $peer) {
            $lines[] = sprintf(t('peer.peer_network.klyuch_token_vhoda_v_sekrete'), $peer->name, $peer->url, AgentIdentity::fingerprintOf($peer->publicKey) ?? '?', $peer->secret)
                . sprintf(t('peer.peer_network.peer_transport'), A2aClient::transport($peer), $peer->identity === '' ? t('peer.peer_network.main_key') : $peer->identity);
        }
        if (count($lines) === 3) {
            $lines[] = t('peer.peer_network.net_peer_add_imya_https');
        }
        if ($this->links !== null) {
            $lines[] = '';
            $lines[] = t('peer.peer_network.links');
            $lines[] = $this->linksText();
        }
        if ($this->keys !== null) {
            $lines[] = '';
            $lines[] = t('peer.peer_network.my_keys');
            foreach ($this->keys->list() as $k) {
                $lines[] = sprintf('• %s — %s', $k['name'] === '' ? t('peer.peer_network.main_key') : $k['name'], $k['fingerprint']);
            }
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
        $names = array_map(static fn($p): string => $p->name, $this->peers->peers());
        foreach ($this->links?->all() ?? [] as $link) {
            if ($link['direction'] === A2aLinks::IN && !in_array($link['name'], $names, true)) {
                $names[] = $link['name'];
            }
        }
        return $names;
    }

    /**
     * Keeps the files of an answer in inbox/<task>/ of the sandbox of the one who gave the task; returns the lines for
     * the conversation ('' — no files).
     *
     * @param list<array{name: string, bytes: string}> $files
     */
    private function keepFiles(OutgoingTask $out, array $files): string {
        if ($files === []) {
            return '';
        }
        $limits = $this->limitsOf === null ? null : ($this->limitsOf)($out->principalId);
        $lines = [];
        foreach ($files as $file) {
            $path = 'inbox/' . $out->remoteId . '/' . \OpenCCK\App\Agent\Media\MediaNames::clean($file['name']);
            $kept = false;
            if ($this->sandbox !== null && $limits !== null) {
                try {
                    $this->sandbox->put($out->principalId, $limits, $path, $file['bytes'], new \Amp\NullCancellation());
                    $kept = true;
                } catch (Throwable $e) {
                    $this->logger->notice('A2A answer file was not kept', ['task' => $out->id, 'error' => $e::class]);
                }
            }
            $lines[] = sprintf(t($kept ? 'peer.a2a_server.file_kept' : 'peer.a2a_server.file_not_kept'), $kept ? $path : $file['name'], strlen($file['bytes']));
        }
        return t('peer.a2a_server.files') . "\n" . implode("\n", $lines);
    }
}
