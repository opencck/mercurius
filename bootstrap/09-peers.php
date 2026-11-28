<?php

declare(strict_types=1);

/**
 * The agent network: keys, the A2A client and server, connections and unknown agents.
 *
 * A part of the start of the agent: bootstrap/index.php includes the parts in order, in one scope. A part reads what the parts
 * before it made and leaves what it makes to the parts after it.
 */

use Mercurius\Messenger\Telegram\TelegramChannel;
use OpenCCK\Domain\Entity\Member;
use OpenCCK\Infrastructure\Http\HttpClients;

/**
 * Made by the parts before this one:
 *
 * @var OpenCCK\App\Peer\A2aServer|null $a2aServer
 * @var Mercurius\Messenger\RoutingChannel $channel
 * @var OpenCCK\Infrastructure\Config\Config $config
 * @var OpenCCK\Infrastructure\Repository\PostgresConversationRepository $conversations
 * @var Amp\Postgres\PostgresConnectionPool $db
 * @var OpenCCK\Infrastructure\Config\LlmSettings $llmSettings
 * @var Monolog\Logger $logger
 * @var OpenCCK\App\Agent\AgentLoop $loop
 * @var OpenCCK\App\Service\OwnerDirectory $ownerDirectory
 * @var OpenCCK\App\Service\ParticipantInbox $participantInbox
 * @var OpenCCK\App\Service\PolicyService $policies
 * @var OpenCCK\Infrastructure\Repository\PostgresMemberRepository $principals
 * @var OpenCCK\Infrastructure\Repository\PostgresRunRepository $runs
 * @var OpenCCK\Infrastructure\Sandbox\HttpSandboxClient|null $sandbox
 * @var OpenCCK\App\Agent\RunScheduler $scheduler
 * @var OpenCCK\Infrastructure\Config\TelegramSettings $telegram
 * @var OpenCCK\App\Agent\Tool\ToolRegistry $tools
 * @var OpenCCK\App\Agent\Trace\Tracer $tracer
 * @var OpenCCK\Infrastructure\Repository\PostgresTranscriptRepository $transcript
 * @var OpenCCK\App\Vault\Vault $vault
 * @var OpenCCK\App\Peer\ProjectChannels $projectChannels
 * @var OpenCCK\App\Service\ProjectPolicies $projectPolicies
 * @var OpenCCK\App\Service\SandboxHolders $sandboxHolders
 * @var OpenCCK\Infrastructure\Repository\PostgresProjectRepository $projectRepository
 */

// spec: brief/network.md#подключения-агентов — A2A v1.0: neighbours connect with their own tokens; the agent gives tasks to its own
$peerRepository = new \OpenCCK\Infrastructure\Repository\PostgresPeerRepository($db);
$selfUrl = $config->appUrl === null ? null : rtrim($config->appUrl, '/');
// The entrance token comes from .env; who speaks is known by the ECDSA key of each agent (kept in the vault).
$a2aToken = \OpenCCK\Infrastructure\Config\Env::raw($_ENV, 'A2A_TOKEN');
$agentIdentity = $vault->enabled()
    ? \OpenCCK\App\Peer\AgentKeys::loadMain($vault, $principals->ensureOwner(TelegramChannel::NAME, $telegram->ownerId, null))
    : \Mercurius\Peer\AgentIdentity::generate();
// spec: brief/network.md#вход-по-websocket-и-несколько-ключей — keys the agent makes itself, the
// transport of a neighbour from its card: a WebSocket opened with the entrance token, or HTTP
$agentKeys = new \OpenCCK\App\Peer\AgentKeys($vault, static fn(): ?Member => $ownerDirectory->principal(), $agentIdentity);
// native WebSocket pings from this side too: every 10 s; three unanswered — the connection is dead and is closed
// spec: brief/internet.md#между-агентами — files go ahead by POST /a2a/files (http, by default) or as bytes over a WebSocket (ws):
// then a message after the handshake may reach 28 MB
$a2aFileTransport = \OpenCCK\Infrastructure\Config\Env::raw($_ENV, 'A2A_FILE_TRANSPORT') === 'ws' ? 'ws' : 'http';
$a2aFrameMax = $a2aFileTransport === 'ws' ? \OpenCCK\Infrastructure\Peer\WebsocketA2aChannel::FILES_MAX : \OpenCCK\Infrastructure\Peer\WebsocketA2aChannel::FRAME_MAX;
$a2aParser = new \Amp\Websocket\Parser\Rfc6455ParserFactory(messageSizeLimit: max($a2aFrameMax, 10_485_760));
$a2aFiles = new \OpenCCK\App\Peer\A2aFiles(new \OpenCCK\Infrastructure\Repository\PostgresA2aFileRepository($db));
\Revolt\EventLoop::unreference(\Revolt\EventLoop::repeat(600, static function () use ($a2aFiles): void {
    $a2aFiles->sweep(new \DateTimeImmutable());
}));
$a2aConnector = new \Amp\Websocket\Client\Rfc6455Connector(new \Amp\Websocket\Client\Rfc6455ConnectionFactory(heartbeatQueue: new \Amp\Websocket\PeriodicHeartbeatQueue(heartbeatPeriod: 10, queuedPingLimit: 3), parserFactory: $a2aParser));
$a2aConnect = static function (string $url, string $token) use ($a2aConnector, $a2aFrameMax): \Mercurius\Peer\A2aChannel {
    if (preg_match('~^wss?://~', $url) !== 1) {
        throw new \RuntimeException('not a websocket address');
    }
    $handshake = (new \Amp\Websocket\Client\WebsocketHandshake($url, ['authorization' => 'Bearer ' . $token]))->withTcpConnectTimeout(10)->withTlsHandshakeTimeout(10);
    return new \OpenCCK\Infrastructure\Peer\WebsocketA2aChannel($a2aConnector->connect($handshake, new \Amp\TimeoutCancellation(20)), $a2aFrameMax);
};
// spec: brief/network.md#подключения-агентов — live connections both ways, shared by the client and
// the server; requests a neighbour sends back on a connection this agent opened are answered by the server
$a2aLinks = new \Mercurius\Peer\A2aLinks();
$a2aServeFor = static function (string $fingerprint, string $name, string $url) use (&$a2aServer): ?\Closure {
    return $a2aServer?->handlerFor($fingerprint, $name, $url);
};
$peerNetwork = new \OpenCCK\App\Peer\PeerNetwork(
    $peerRepository,
    $principals,
    $vault,
    new \Mercurius\Peer\A2aClient(HttpClients::create(), $agentIdentity, $agentKeys, $a2aConnect, $a2aLinks, $a2aServeFor, $selfUrl ?? '', $a2aFileTransport),
    $conversations,
    $transcript,
    $channel,
    $selfUrl ?? 'local:' . $telegram->ownerId,
    $logger,
    $agentIdentity,
    static fn(): ?Member => $ownerDirectory->principal(),
    $agentKeys,
    $a2aLinks,
    $sandbox,
    static fn(int $principalId): ?\OpenCCK\Domain\Agent\SandboxLimits => ($p = $principals->find($principalId)) === null ? null : $policies->forPrincipal($p)->sandbox,
);
// spec: brief/participants.md#доступ-к-проектам-и-передача — what the code tells a participant reaches an agent over the
//       network of agents, as it reaches a person in their private chat
$participantInbox->useAgents(static function (Member $agent, string $text) use ($peerNetwork, $ownerDirectory): bool {
    $owner = $ownerDirectory->principal();
    if ($owner === null || $agent->displayName === null || !in_array($agent->displayName, $peerNetwork->names(), true)) {
        return false;
    }
    try {
        $peerNetwork->delegate($owner, $agent->displayName, $text, TelegramChannel::NAME, $ownerDirectory->chatId(), 0, []);
    } catch (\DomainException) {
        return false;
    }
    return true;
});
// spec: brief/network.md#события-проекта-для-агентов — what is said in the chats and topics of a project reaches the agents
//       that work with it, over their live connections
$projectEvents = new \OpenCCK\App\Peer\ProjectEvents(
    $runs,
    $conversations,
    $transcript,
    new \OpenCCK\Infrastructure\Repository\PostgresProjectRepository($db),
    $principals,
    static function (int $principalId, string $method, array $params) use ($peerRepository, $a2aLinks): bool {
        $sent = false;
        foreach ($peerRepository->keys() as $key) {
            if ($key['principal'] === $principalId && !$key['revoked']) {
                $socket = $a2aLinks->to($key['fingerprint']);
                if ($socket !== null) {
                    $socket->notify($method, $params);
                    $sent = true;
                }
            }
        }
        return $sent;
    },
    $logger,
    $projectChannels->subscribed(...),
);
$tracer->onRunFinished($projectEvents->finished(...));
// spec: brief/network.md#потоки-мест-для-агентов — the streams of places the owner gives to agents of the network
$placeStreams = new \OpenCCK\Infrastructure\Repository\PostgresPlaceStreamRepository($db);
$projectEvents->useStreams($placeStreams);
$tools->register(new \OpenCCK\App\Agent\Tool\Agents\PeerDelegateTool($peerNetwork, $peerRepository, $conversations, $sandbox));
// spec: brief/network.md#вызов-и-раздача-соседям — call one neighbour and wait, or give parts to several and merge
$tools->register(new \OpenCCK\App\Agent\Tool\Agents\PeerCallTool($peerNetwork, $peerRepository, $conversations));
$tools->register(new \OpenCCK\App\Agent\Tool\Agents\PeerOrchestrateTool($peerNetwork, $peerRepository, $conversations));
$tools->register(new \OpenCCK\App\Agent\Tool\Agents\PeerCancelTool($peerNetwork));
$tools->register(new \OpenCCK\App\Agent\Tool\Agents\PeerLinksTool($peerNetwork));
// spec: brief/network.md#подключения-агентов — an opened connection is kept: idle ones are not closed,
// dropped ones are opened again (after a restart too); WebSocket pings keep a live one through NPM
\Revolt\EventLoop::unreference(\Revolt\EventLoop::repeat(30, static function (string $id) use ($peerNetwork): void {
    $peerNetwork->keepAlive();
}));
$tools->register(new \OpenCCK\App\Agent\Tool\Agents\PeerKeysTool($peerNetwork, $agentKeys));
// spec: brief/network.md#незнакомый-агент-стучится — an unknown agent knocks, the owner decides
$agentRequests = new \OpenCCK\App\Peer\AgentRequests($peerRepository, $principals, $channel, $ownerDirectory, $logger, conversations: $conversations, transcript: $transcript);
// ws (by default) — neighbours connect by WebSocket only; http — by POST /a2a only
$a2aTransport = (\OpenCCK\Infrastructure\Config\Env::raw($_ENV, 'A2A_TRANSPORT') ?? 'ws') === 'http' ? 'http' : 'ws';
$a2aServer = $selfUrl === null || $a2aToken === null || !$vault->enabled() ? null : new \OpenCCK\App\Peer\A2aServer(
    $peerRepository,
    $principals,
    $loop,
    $runs,
    $transcript,
    $conversations,
    $policies,
    $llmSettings->model,
    $selfUrl,
    \OpenCCK\Infrastructure\Config\Env::raw($_ENV, 'A2A_NAME') ?? 'Mercurius',
    \OpenCCK\Infrastructure\Config\Env::raw($_ENV, 'A2A_DESCRIPTION') ?? 'Персональный агент: задачи по правилам и правам своего владельца.',
    $logger,
    $agentIdentity,
    $a2aToken,
    transport: $a2aTransport,
    links: $a2aLinks,
    sandbox: $sandbox,
    requests: $agentRequests,
    files: $a2aFiles,
    // spec: brief/network.md#как-сделано-связь-двух-агентов — the cost of a task's run (its workers too) in USD goes to the leader's trace
    costOf: static function (int $runId) use ($db): ?float {
        $row = $db->execute(
            'SELECT coalesce(sum(cost_usd), 0)::float8 AS usd FROM llm_calls WHERE run_id = ? OR run_id IN (SELECT id FROM runs WHERE parent_run_id = ?)',
            [$runId, $runId],
        )->fetchRow();
        $usd = $row['usd'] ?? null;
        return is_float($usd) ? $usd : null;
    },
);
// spec: brief/agent-loop.md#запуск-начатый-внешними-данными — the answer of a neighbour marks the run that reads it
$peerNetwork->onAnswered($scheduler->taint(...));
// spec: brief/network.md#справочник-сети — who the agent knows and how to reach them, for the permission peers
$peerDirectory = new \OpenCCK\App\Peer\PeerDirectory(
    $peerRepository,
    static fn(): array => array_map(static fn(array $l): array => ['direction' => $l['direction'], 'name' => $l['name'], 'fingerprint' => $l['fingerprint'], 'opened' => $l['opened']], $a2aLinks->all()),
    static function () use (&$a2aServer, $agentIdentity, $selfUrl): array {
        $card = $a2aServer?->card() ?? [];
        /** @var mixed $meta */
        $meta = $card['mercurius'] ?? null;
        $meta = is_array($meta) ? $meta : [];
        $text = static function (mixed $value): string {
            return is_string($value) ? $value : '';
        };
        return [
            'name' => $text($card['name'] ?? null),
            'url' => $selfUrl === null ? '' : $selfUrl . '/.well-known/agent-card.json',
            'transport' => $text($meta['transport'] ?? null),
            'ws' => $text($meta['ws'] ?? null),
            'fingerprint' => $agentIdentity->fingerprint(),
            'publicKey' => $agentIdentity->publicKey,
        ];
    },
);
$tools->register(new \OpenCCK\App\Agent\Tool\Agents\PeerDirectoryTool($peerDirectory, $peerNetwork));
if ($a2aServer !== null) {
    $a2aServer->useDirectory($peerDirectory);
    $scheduler->useJobRuns($a2aServer);
    $peerNetwork->onFinished($a2aServer->chainAnswered(...));
    // spec: brief/network.md#опознание-агентов-ключи-ecdsa — the extended card names the tools of the asking agent's own policy
    $a2aServer->useProjects($projectChannels);
    // spec: brief/participants.md#права-проекта — a task of an agent in a project runs with the project's rights
    $a2aServer->useProjectPolicies($projectPolicies, $projectRepository, $sandboxHolders);
    $a2aServer->useStreams($placeStreams);
    $a2aServer->useSkills(static function (Member $agent) use ($tools, $policies): array {
        $skills = [];
        foreach (array_slice($tools->definitions($policies->forPrincipal($agent)), 0, 30) as $definition) {
            $skills[] = ['id' => $definition->name, 'name' => $definition->name, 'description' => mb_substr(trim(explode("\n", $definition->description)[0]), 0, 160)];
        }
        return $skills;
    });
}
