<?php

declare(strict_types=1);

namespace OpenCCK\App\Peer;

use Amp\DeferredCancellation;
use Closure;
use DateTimeImmutable;
use Mercurius\Llm\Role as LlmRole;
use OpenCCK\App\Agent\AgentLoop;
use OpenCCK\App\Agent\JobRuns;
use OpenCCK\App\Agent\PromptBuilder;
use OpenCCK\App\Agent\SilentObserver;
use OpenCCK\App\Agent\Tool\SecretMasker;
use OpenCCK\App\Service\PolicyService;
use OpenCCK\Domain\Agent\Run;
use OpenCCK\Domain\Agent\RunStatus;
use OpenCCK\Domain\Entity\Principal;
use OpenCCK\Domain\Entity\PrincipalKind;
use OpenCCK\Domain\Peer\A2aTask;
use OpenCCK\Domain\Repository\ConversationRepository;
use OpenCCK\Domain\Repository\PeerRepository;
use OpenCCK\Domain\Repository\PrincipalRepository;
use OpenCCK\Domain\Repository\RunRepository;
use OpenCCK\Domain\Repository\TranscriptRepository;
use Psr\Log\LoggerInterface;
use SensitiveParameter;
use Throwable;

use function Amp\async;

use function Mercurius\I18n\t;

/**
 * The side that takes tasks from neighbouring agents over A2A v1.0 (JSON-RPC). The entrance needs the token of this
 * instance (A2A_TOKEN); who speaks is known by the ECDSA key that signs each request (method, path, time, a one-time
 * number, the hash of the body), and the owner lets an agent in by the fingerprint of its key. A known agent is a
 * participant of kind agent with its own policy; its task runs under that policy, approvals go to this agent's owner,
 * the answer leaves without secrets and signed by this agent's key. Checks in order: token, signature, time, replay,
 * known key, rate, method, a repeated message, a loop in the chain, the cap of turns in one context.
 *
 * spec: BRIEF.md#сеть-агентов — the protocol, trust and safety
 * ref: references/hermes-agent/plugins/platforms/a2a/adapter.py@e96221fa:L229-L270 (do_POST) — ordered checks, first
 *      failure wins; L577-L588 — idempotency by message; L379-L396 (_watchdog_loop) — stale tasks fail
 * ref: references/hermes-agent/plugins/platforms/a2a/protocol.py@e96221fa:L23-L53 — states, roles, error codes, the
 *      INPUT_REQUIRED marker, the ping-pong cap; L60-L91 (build_agent_card); L258-L276 — 60 requests a minute
 * ref: references/hermes-agent/plugins/platforms/a2a/security.py@e96221fa:L101-L130 (authenticate) — identity from the
 *      credential, never the body. Here the credential is a signature by the agent's key (the owner's decision of
 *      2026-11-17), the token only opens the entrance; answers are signed too, hermes does not sign them
 */
final class A2aServer implements JobRuns {
    public const CHANNEL = 'a2a';
    public const MAX_DEPTH = 3;
    public const MAX_TURNS = 5;
    public const RATE = 60;
    public const TEXT_MAX = 20_000;
    public const STALE_SECONDS = 3600;
    public const CLOCK_SKEW = 300;
    public const INPUT_REQUIRED_MARKER = '[INPUT_REQUIRED]';

    public const ERR_PARSE = -32700;
    public const ERR_INVALID_PARAMS = -32602;
    public const ERR_METHOD_NOT_FOUND = -32601;
    public const ERR_TASK_NOT_FOUND = -32001;
    public const ERR_TASK_NOT_CANCELABLE = -32002;
    public const ERR_UNAUTHORIZED = -32050;
    public const ERR_RATE_LIMITED = -32051;

    /** method => [handler, is A2A v1.0 name] */
    private const METHODS = [
        'SendMessage' => ['send', true], 'message/send' => ['send', false],
        'GetTask' => ['get', true], 'tasks/get' => ['get', false],
        'CancelTask' => ['cancel', true], 'tasks/cancel' => ['cancel', false],
    ];

    /** @var array<int, list<float>> request times by agent */
    private array $hits = [];
    /** @var array<string, DeferredCancellation> */
    private array $cancels = [];
    /** @var Closure(): DateTimeImmutable */
    private readonly Closure $now;

    /** @param (Closure(): DateTimeImmutable)|null $now */
    public function __construct(
        private readonly PeerRepository $peers,
        private readonly PrincipalRepository $principals,
        private readonly AgentLoop $loop,
        private readonly RunRepository $runs,
        private readonly TranscriptRepository $transcript,
        private readonly ConversationRepository $conversations,
        private readonly PolicyService $policies,
        private readonly string $model,
        /** this instance in a chain: its public address */
        private readonly string $self,
        private readonly string $name,
        private readonly string $description,
        private readonly LoggerInterface $logger,
        private readonly AgentIdentity $identity,
        #[SensitiveParameter] private readonly string $token,
        ?Closure $now = null,
        /** ws — neighbours connect by WebSocket (/a2a/ws) and prove their keys once; http — POST /a2a, every request signed */
        public readonly string $transport = 'http',
        /** the live connections: an incoming one is listed here once the agent proved its key */
        private readonly ?A2aLinks $links = null,
        /** files a neighbour sends go into the sandbox home of its agent; null — they are not kept */
        private readonly ?\OpenCCK\App\Agent\Sandbox\Sandbox $sandbox = null,
        /** an unknown agent that proved its key is shown to the owner; null — it is only told to ask for /peer allow */
        private readonly ?AgentRequests $requests = null,
    ) {
        $this->now = $now ?? static fn(): DateTimeImmutable => new DateTimeImmutable();
    }

    /**
     * Who answers requests a neighbour sends back on a connection this agent opened: the agent of that key, if this
     * agent's owner let it in; null — its requests are refused.
     *
     * @return (\Closure(array<array-key, mixed>): array<string, mixed>)|null
     */
    public function handlerFor(string $fingerprint): ?\Closure {
        $id = $this->peers->principalByFingerprint($fingerprint);
        $agent = $id === null ? null : $this->principals->find($id);
        if ($agent === null || $agent->kind !== PrincipalKind::Agent) {
            return null;
        }
        return fn(array $request): array => $this->handle($agent, $request, 'out')[1];
    }

    /** The entrance token of this instance, as the Authorization header carries it. */
    public function entrance(string $authorization): bool {
        return hash_equals('Bearer ' . $this->token, trim($authorization));
    }

    public function identity(): AgentIdentity {
        return $this->identity;
    }

    /**
     * Who sends the request: the token of the entrance, then the signature by the agent's key.
     *
     * @param array<string, string> $headers names in lower case
     * @return array{Principal|null, int, string} the agent (null — refused), the HTTP status, why
     */
    public function authenticate(array $headers, string $method, string $path, string $body): array {
        if (!hash_equals('Bearer ' . $this->token, trim($headers['authorization'] ?? ''))) {
            return [null, 401, 'unauthorized'];
        }
        $key = base64_decode($headers['x-a2a-key'] ?? '', true);
        $timestamp = $headers['x-a2a-timestamp'] ?? '';
        $nonce = $headers['x-a2a-nonce'] ?? '';
        $signature = $headers['x-a2a-signature'] ?? '';
        $fingerprint = $key === false ? null : AgentIdentity::fingerprintOf($key);
        if ($key === false || $fingerprint === null || preg_match('~^[A-Za-z0-9_-]{16,64}$~', $nonce) !== 1 || !ctype_digit($timestamp)
            || !AgentIdentity::verify($key, AgentIdentity::requestData($method, $path, $timestamp, $nonce, $body), $signature)) {
            return [null, 401, 'the request is not signed by an agent key'];
        }
        if (abs(($this->now)()->getTimestamp() - (int) $timestamp) > self::CLOCK_SKEW) {
            return [null, 401, 'the signature is too old'];
        }
        if (!$this->peers->useNonce($nonce)) {
            return [null, 401, 'a replayed request'];
        }
        $id = $this->peers->principalByFingerprint($fingerprint);
        $principal = $id === null ? null : $this->principals->find($id);
        if ($principal === null || $principal->kind !== PrincipalKind::Agent) {
            $this->logger->notice('Unknown agent key', ['fingerprint' => $fingerprint]);
            return [null, 403, $this->unknown($fingerprint, $headers['x-a2a-name'] ?? '', $headers['x-a2a-url'] ?? '', 'http')];
        }
        return [$principal, 200, ''];
    }

    /**
     * One WebSocket connection of a neighbour (its entrance token was checked before the upgrade): the handshake proves
     * whose key it is, then each frame is a JSON-RPC request answered on the same connection. A connection that does
     * not prove a known key in $timeout seconds is closed.
     *
     * spec: BRIEF.md#вход-по-websocket-и-несколько-ключей-решение-владельца-2026-11-17
     */
    public function serveSocket(A2aChannel $channel, float $timeout = 10.0): void {
        try {
            $known = $this->handshake($channel, $timeout);
            if ($known === null) {
                return;
            }
            [$agent, $fingerprint] = $known;
            // spec: BRIEF.md#подключения-агентов-решение-владельца-2026-11-17 — the connection goes both ways and is listed
            $socket = A2aSocket::accepted($channel, fn(array $request): array => $this->handle($agent, $request, 'ws')[1]);
            $this->links?->add(A2aLinks::IN, $agent->displayName ?? ('#' . $agent->id), $fingerprint, $socket);
            $socket->join();
        } catch (Throwable $e) {
            $this->logger->notice('A2A connection ended', ['error' => $e::class]);
        } finally {
            $channel->close();
        }
    }

    /**
     * The agent that proved its key on this connection and the key's fingerprint; null — refused (the connection is
     * told why).
     *
     * @return array{Principal, string}|null
     */
    private function handshake(A2aChannel $channel, float $timeout): ?array {
        $deadline = new \Amp\TimeoutCancellation($timeout);
        $hello = self::frame($channel->receive($deadline));
        $key = isset($hello['key']) && is_string($hello['key']) ? $hello['key'] : '';
        $clientNonce = isset($hello['nonce']) && is_string($hello['nonce']) ? $hello['nonce'] : '';
        $fingerprint = AgentIdentity::fingerprintOf($key);
        if (($hello['type'] ?? null) !== 'hello' || $fingerprint === null || preg_match('~^[A-Za-z0-9_-]{16,64}$~', $clientNonce) !== 1) {
            $this->deny($channel, 'a hello with a P-256 key and a random number was expected');
            return null;
        }
        $serverNonce = bin2hex(random_bytes(16));
        $channel->send(json_encode([
            'type' => 'challenge',
            'nonce' => $serverNonce,
            'key' => $this->identity->publicKey,
            'signature' => $this->identity->sign(AgentIdentity::wsServerData($clientNonce, $serverNonce, $fingerprint)),
        ], JSON_THROW_ON_ERROR));
        $auth = self::frame($channel->receive($deadline));
        $signature = isset($auth['signature']) && is_string($auth['signature']) ? $auth['signature'] : '';
        if (($auth['type'] ?? null) !== 'auth' || !AgentIdentity::verify($key, AgentIdentity::wsClientData($serverNonce, $clientNonce, $this->identity->fingerprint()), $signature)) {
            $this->deny($channel, 'the random number is not signed by the key of the hello');
            return null;
        }
        $id = $this->peers->principalByFingerprint($fingerprint);
        $agent = $id === null ? null : $this->principals->find($id);
        if ($agent === null || $agent->kind !== PrincipalKind::Agent) {
            $this->logger->notice('Unknown agent key', ['fingerprint' => $fingerprint]);
            $this->deny($channel, $this->unknown($fingerprint, isset($hello['name']) && is_string($hello['name']) ? $hello['name'] : '', isset($hello['url']) && is_string($hello['url']) ? $hello['url'] : '', 'ws'));
            return null;
        }
        $channel->send(json_encode(['type' => 'ready', 'agent' => $agent->displayName], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        return [$agent, $fingerprint];
    }

    /**
     * Why an agent whose key nobody let in is turned away; it knocks at the owner, who decides with a card.
     *
     * spec: BRIEF.md#незнакомый-агент-стучится-решение-владельца-2026-11-17
     */
    private function unknown(string $fingerprint, string $name, string $url, string $transport): string {
        return $this->requests?->knock($fingerprint, mb_substr($name, 0, 100), mb_substr($url, 0, 300), $transport)
            ?? 'unknown agent ' . $fingerprint . ': its owner lets it in with /peer allow';
    }

    private function deny(A2aChannel $channel, string $why): void {
        try {
            $channel->send(json_encode(['type' => 'denied', 'reason' => $why], JSON_THROW_ON_ERROR));
        } catch (Throwable) {
            // already gone
        }
        $channel->close();
    }

    /** @return array<array-key, mixed> */
    private static function frame(?string $text): array {
        try {
            /** @var mixed $data */
            $data = $text === null ? null : json_decode($text, true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }
        return is_array($data) ? $data : [];
    }

    /**
     * Headers that sign an answer with this agent's key, bound to the request by its one-time number.
     *
     * @return array<non-empty-string, string>
     */
    public function sign(string $nonce, string $body): array {
        return [
            'x-a2a-key' => base64_encode($this->identity->publicKey),
            'x-a2a-signature' => $this->identity->sign(AgentIdentity::responseData($nonce, $body)),
        ];
    }

    /** wss://<this agent>/a2a/ws */
    public function socketUrl(): string {
        return (preg_replace('~^http~', 'ws', rtrim($this->self, '/')) ?? $this->self) . '/a2a/ws';
    }

    /** @return array<string, mixed> the A2A v1.0 agent card */
    public function card(): array {
        $url = rtrim($this->self, '/') . '/a2a';
        return [
            'name' => $this->name,
            'description' => $this->description,
            'url' => $url,
            'version' => '1.0.0',
            'provider' => ['organization' => 'Mercurius', 'url' => $this->self],
            'supportedInterfaces' => [['url' => $url, 'protocolBinding' => 'JSONRPC', 'protocolVersion' => '1.0']],
            'capabilities' => ['streaming' => false, 'pushNotifications' => false, 'stateTransitionHistory' => false, 'extendedAgentCard' => false],
            'defaultInputModes' => ['text/plain'],
            'defaultOutputModes' => ['text/plain'],
            'skills' => [['id' => 'general', 'name' => 'general', 'description' => t('peer.a2a_server.zadachi_vladeltsa_etogo_agenta_po'), 'tags' => ['general']]],
            'securitySchemes' => ['bearer' => ['type' => 'http', 'scheme' => 'bearer']],
            'security' => [['bearer' => []]],
            // Requests and answers are signed by ECDSA P-256 keys; the leader pins this key when it adds the agent.
            'mercurius' => ['publicKey' => $this->identity->publicKey, 'fingerprint' => $this->identity->fingerprint(), 'signing' => 'x-a2a-signature']
                // spec: BRIEF.md#вход-по-websocket-и-несколько-ключей-решение-владельца-2026-11-17 — the transport this agent takes
                + ($this->transport === 'ws' ? ['transport' => 'ws', 'ws' => $this->socketUrl()] : ['transport' => 'http']),
        ];
    }

    /**
     * A JSON-RPC request of an authenticated agent.
     *
     * @param array<array-key, mixed> $request
     * @return array{int, array<string, mixed>} HTTP status and the JSON-RPC answer
     */
    public function handle(Principal $agent, array $request, string $via = 'http'): array {
        /** @var mixed $id */
        $id = $request['id'] ?? null;
        $method = isset($request['method']) && is_string($request['method']) ? $request['method'] : '';
        $params = $request['params'] ?? [];
        if (!is_array($params)) {
            return [200, self::error($id, self::ERR_INVALID_PARAMS, 'params must be an object')];
        }
        if (!$this->allow($agent->id)) {
            return [429, self::error($id, self::ERR_RATE_LIMITED, 'rate limit exceeded')];
        }
        [$handler, $v1] = self::METHODS[$method] ?? ['', false];
        return match ($handler) {
            'send' => [200, $this->send($agent, $id, $params, $v1, $via)],
            'get' => [200, $this->get($agent, $id, $params)],
            'cancel' => [200, $this->cancel($agent, $id, $params)],
            default => [200, self::error($id, self::ERR_METHOD_NOT_FOUND, 'method not found: ' . $method)],
        };
    }

    /** Tasks that hang longer than an hour fail: the neighbour stops waiting. */
    public function sweep(): int {
        $stale = $this->peers->stale(($this->now)()->modify(sprintf('-%d seconds', self::STALE_SECONDS)));
        foreach ($stale as $task) {
            ($this->cancels[$task->id] ?? null)?->cancel();
            $this->peers->updateTask($task->id, A2aTask::FAILED, null, t('peer.a2a_server.zadacha_ne_zakonchilas_za_chas'));
        }
        return count($stale);
    }

    public function owns(Run $run): bool {
        return $this->conversations->meta($run->conversationId)?->channel === self::CHANNEL;
    }

    public function continueRun(Run $run, bool $decided): void {
        $task = $this->peers->taskByRun($run->id);
        $agent = $this->principals->find($run->principalId);
        if ($task === null || $agent === null) {
            return;
        }
        if ($decided) {
            $run = $this->runs->transition($run->id, RunStatus::WaitingApproval, RunStatus::Running);
            if ($run === null) {
                return;
            }
        }
        $this->launch($task, $agent, $run);
    }

    /**
     * @param array<array-key, mixed> $params
     * @param string $via how the task came: ws (it connected to me), http, out (over my connection to it)
     * @return array<string, mixed>
     */
    private function send(Principal $agent, mixed $id, array $params, bool $v1, string $via = 'http'): array {
        $message = isset($params['message']) && is_array($params['message']) ? $params['message'] : [];
        $messageId = isset($message['messageId']) && is_string($message['messageId']) ? trim($message['messageId']) : '';
        $text = self::text($message);
        $files = self::files($message);
        if ($text === '' && $files !== []) {
            $text = t('peer.a2a_server.files_only');
        }
        if ($messageId === '' || strlen($messageId) > 200 || $text === '' || mb_strlen($text) > self::TEXT_MAX || count($files) > 5) {
            return self::error($id, self::ERR_INVALID_PARAMS, 'message needs messageId and text parts up to ' . self::TEXT_MAX . ' characters');
        }
        $existing = $this->peers->taskByMessage($agent->id, $messageId);
        if ($existing !== null) {
            return self::result($id, $this->view($existing), $v1);
        }
        $contextId = isset($message['contextId']) && is_string($message['contextId']) && preg_match('~^[\w.:-]{1,100}$~', $message['contextId']) === 1
            ? $message['contextId']
            : 'ctx-' . substr(hash('sha256', $agent->id . "\0" . $messageId), 0, 16);
        [$depth, $path] = self::chain($params);
        $taskId = 'task-' . bin2hex(random_bytes(8));
        $refusal = match (true) {
            in_array($this->self, $path, true) => t('peer.a2a_server.tsikl_delegirovaniya_zadacha_uzhe_prohod'),
            $depth >= self::MAX_DEPTH => sprintf(t('peer.a2a_server.tsepochka_delegirovaniya_dlinnee'), self::MAX_DEPTH),
            $this->peers->contextTurns($agent->id, $contextId) >= self::MAX_TURNS => sprintf(t('peer.a2a_server.v_odnom_kontekste_ne_bolshe'), self::MAX_TURNS),
            default => null,
        };
        if (!$this->peers->createTask($taskId, $agent->id, $contextId, $messageId, $refusal === null ? A2aTask::SUBMITTED : A2aTask::REJECTED, $depth, [...$path, $this->self])) {
            $raced = $this->peers->taskByMessage($agent->id, $messageId);
            return $raced === null ? self::error($id, self::ERR_INVALID_PARAMS, 'task was not created') : self::result($id, $this->view($raced), $v1);
        }
        if ($refusal !== null) {
            $this->peers->updateTask($taskId, A2aTask::REJECTED, null, $refusal);
            $this->logger->notice('A2A task refused', ['agent' => $agent->id, 'reason' => $refusal]);
        } else {
            $this->start($agent, $taskId, $contextId, $text . $this->keep($agent, $taskId, $files), $via);
        }
        $task = $this->peers->task($taskId);
        return $task === null ? self::error($id, self::ERR_TASK_NOT_FOUND, 'task not found') : self::result($id, $this->view($task), $v1);
    }

    private function start(Principal $agent, string $taskId, string $contextId, string $text, string $via = 'http'): void {
        $policy = $this->policies->forPrincipal($agent);
        $conversation = $this->conversations->openConversation($agent->id, self::CHANNEL, 'a2a-' . $agent->id . '-' . $contextId);
        $this->conversations->setMeta($conversation, PromptBuilder::system($agent, ($this->now)()), $this->model);
        $run = $this->runs->start($conversation, $agent->id, $this->model, $policy->run);
        if ($run === null) {
            $this->peers->updateTask($taskId, A2aTask::REJECTED, null, t('peer.a2a_server.v_etom_kontekste_uzhe_idet'));
            return;
        }
        // spec: BRIEF.md#незнакомый-агент-стучится-решение-владельца-2026-11-17 — who gave the task and how it came: the agent
        //      must not take the neighbour for someone it should connect to
        $this->transcript->appendUser($conversation, $run->id, sprintf(
            t('peer.a2a_server.zadacha_ot_agenta_po_seti'),
            $agent->displayName ?? t('peer.a2a_server.bez_imeni'),
            t('peer.a2a_server.via_' . (in_array($via, ['ws', 'http', 'out'], true) ? $via : 'http')),
            self::INPUT_REQUIRED_MARKER,
            $text,
        ));
        $this->peers->updateTask($taskId, A2aTask::WORKING, $run->id, null);
        $task = $this->peers->task($taskId);
        if ($task !== null) {
            $this->launch($task, $agent, $run);
        }
    }

    private function launch(A2aTask $task, Principal $agent, Run $run): void {
        $cancel = $this->cancels[$task->id] = new DeferredCancellation();
        async(function () use ($task, $agent, $run, $cancel): void {
            try {
                $final = $this->loop->run($run, $this->policies->forPrincipal($agent), $agent, new SilentObserver(), $cancel->getCancellation());
            } catch (Throwable $e) {
                $this->logger->error('A2A task failed', ['task' => $task->id, 'error' => $e::class]);
                $this->peers->updateTask($task->id, A2aTask::FAILED, null, t('peer.a2a_server.vnutrennyaya_oshibka_agenta'));
                return;
            } finally {
                unset($this->cancels[$task->id]);
            }
            if ($final->status === RunStatus::WaitingApproval) {
                // The owner of this agent decides; the run goes on after that through continueRun.
                return;
            }
            $current = $this->peers->task($task->id);
            if ($current !== null && $current->terminal()) {
                return;
            }
            [$state, $text] = $this->outcome($final);
            $this->peers->updateTask($task->id, $state, null, $text);
        });
    }

    /** @return array{string, string} */
    private function outcome(Run $final): array {
        foreach (array_reverse($this->transcript->runMessages($final->id)) as $message) {
            if ($message->role === LlmRole::Assistant && $message->toolCalls === [] && trim($message->content) !== '') {
                $text = SecretMasker::mask(trim($message->content));
                return str_starts_with($text, self::INPUT_REQUIRED_MARKER)
                    ? [A2aTask::INPUT_REQUIRED, trim(substr($text, strlen(self::INPUT_REQUIRED_MARKER)))]
                    : [A2aTask::COMPLETED, $text];
            }
        }
        return [A2aTask::FAILED, t('peer.a2a_server.itoga_net') . ($final->stopReason?->label() ?? t('peer.a2a_server.zapusk_ne_zakonchen')) . ').'];
    }

    /**
     * @param array<array-key, mixed> $params
     * @return array<string, mixed>
     */
    private function get(Principal $agent, mixed $id, array $params): array {
        $task = $this->own($agent, $params);
        return $task === null ? self::error($id, self::ERR_TASK_NOT_FOUND, 'task not found') : ['jsonrpc' => '2.0', 'id' => $id, 'result' => $this->view($task)];
    }

    /**
     * @param array<array-key, mixed> $params
     * @return array<string, mixed>
     */
    private function cancel(Principal $agent, mixed $id, array $params): array {
        $task = $this->own($agent, $params);
        if ($task === null) {
            return self::error($id, self::ERR_TASK_NOT_FOUND, 'task not found');
        }
        if ($task->terminal()) {
            return self::error($id, self::ERR_TASK_NOT_CANCELABLE, 'task is not cancelable');
        }
        ($this->cancels[$task->id] ?? null)?->cancel();
        $this->peers->updateTask($task->id, A2aTask::CANCELED, null, t('peer.a2a_server.otmeneno_veduschim_agentom'));
        $canceled = $this->peers->task($task->id) ?? $task;
        return ['jsonrpc' => '2.0', 'id' => $id, 'result' => $this->view($canceled)];
    }

    /** @param array<array-key, mixed> $params */
    private function own(Principal $agent, array $params): ?A2aTask {
        $taskId = isset($params['id']) && is_string($params['id']) ? $params['id'] : '';
        $task = $taskId === '' ? null : $this->peers->task($taskId);
        return $task !== null && $task->principalId === $agent->id ? $task : null;
    }

    /** @return array<string, mixed> */
    private function view(A2aTask $task): array {
        $status = ['state' => $task->state, 'timestamp' => ($this->now)()->format('Y-m-d\TH:i:s.v\Z')];
        $view = ['id' => $task->id, 'contextId' => $task->contextId, 'status' => $status];
        if ($task->result !== null && $task->terminal()) {
            $view['status']['message'] = ['role' => 'ROLE_AGENT', 'messageId' => $task->id . '-result', 'parts' => [['text' => $task->result, 'mediaType' => 'text/plain']]];
            if ($task->state === A2aTask::COMPLETED) {
                $view['artifacts'] = [['artifactId' => $task->id . '-answer', 'parts' => [['text' => $task->result, 'mediaType' => 'text/plain']]]];
            }
        }
        return $view;
    }

    private function allow(int $agentId): bool {
        $now = microtime(true);
        $recent = array_values(array_filter($this->hits[$agentId] ?? [], static fn(float $t): bool => $t > $now - 60.0));
        if (count($recent) >= self::RATE) {
            $this->hits[$agentId] = $recent;
            return false;
        }
        $recent[] = $now;
        $this->hits[$agentId] = $recent;
        return true;
    }

    /**
     * Keeps the files of a task in the sandbox home of the agent that sent them (inbox/<task>/) and says where; without
     * a sandbox it says what came and that it was not kept.
     *
     * spec: BRIEF.md#личные-сообщения-участникам — not only text: files from other agents
     *
     * @param list<array{name: string, bytes: string}> $files
     */
    private function keep(Principal $agent, string $taskId, array $files): string {
        if ($files === []) {
            return '';
        }
        $limits = $this->policies->forPrincipal($agent)->sandbox;
        $lines = [];
        foreach ($files as $file) {
            $path = 'inbox/' . $taskId . '/' . $file['name'];
            $kept = false;
            if ($this->sandbox !== null && $limits !== null) {
                try {
                    $this->sandbox->put($agent->id, $limits, $path, $file['bytes'], new \Amp\NullCancellation());
                    $kept = true;
                } catch (Throwable $e) {
                    $this->logger->notice('A2A file was not kept', ['task' => $taskId, 'error' => $e::class]);
                }
            }
            $lines[] = sprintf(t($kept ? 'peer.a2a_server.file_kept' : 'peer.a2a_server.file_not_kept'), $kept ? $path : $file['name'], strlen($file['bytes']));
        }
        return "\n\n" . t('peer.a2a_server.files') . "\n" . implode("\n", $lines);
    }

    /**
     * The files of a message: parts with raw bytes (base64) and a name, each up to 5 MB.
     *
     * @param array<array-key, mixed> $message
     * @return list<array{name: string, bytes: string}>
     */
    public static function files(array $message): array {
        $files = [];
        /** @var mixed $part */
        foreach (isset($message['parts']) && is_array($message['parts']) ? $message['parts'] : [] as $part) {
            if (!is_array($part) || !isset($part['raw']) || !is_string($part['raw'])) {
                continue;
            }
            $bytes = base64_decode($part['raw'], true);
            if ($bytes === false || strlen($bytes) > 5_000_000) {
                continue;
            }
            /** @var mixed $name */
            $name = $part['filename'] ?? 'file';
            $clean = preg_replace('~[\x00-\x1F\x7F/\\\\]+~u', '_', is_string($name) ? $name : 'file') ?? 'file';
            $clean = trim(mb_substr($clean, 0, 100), ". \t");
            $files[] = ['name' => $clean === '' ? 'file' : $clean, 'bytes' => $bytes];
        }
        return $files;
    }

    /** @param array<array-key, mixed> $message */
    public static function text(array $message): string {
        $texts = [];
        /** @var mixed $part */
        foreach (isset($message['parts']) && is_array($message['parts']) ? $message['parts'] : [] as $part) {
            if (is_array($part) && isset($part['text']) && is_string($part['text'])) {
                $texts[] = $part['text'];
            }
        }
        return trim(implode("\n", $texts));
    }

    /**
     * The chain of delegation carried in the request: depth and the instances passed.
     *
     * @param array<array-key, mixed> $params
     * @return array{int, list<string>}
     */
    public static function chain(array $params): array {
        $meta = isset($params['metadata']) && is_array($params['metadata']) && isset($params['metadata']['mercurius']) && is_array($params['metadata']['mercurius'])
            ? $params['metadata']['mercurius']
            : [];
        $depth = isset($meta['depth']) && is_int($meta['depth']) ? max(0, $meta['depth']) : 0;
        $path = [];
        /** @var mixed $item */
        foreach (isset($meta['path']) && is_array($meta['path']) ? $meta['path'] : [] as $item) {
            if (is_string($item) && count($path) < 20) {
                $path[] = $item;
            }
        }
        return [$depth, $path];
    }

    /**
     * @param array<string, mixed> $task
     * @return array<string, mixed>
     */
    private static function result(mixed $id, array $task, bool $v1): array {
        return ['jsonrpc' => '2.0', 'id' => $id, 'result' => $v1 ? ['task' => $task] : $task];
    }

    /** @return array<string, mixed> */
    public static function error(mixed $id, int $code, string $message): array {
        return ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $code, 'message' => $message]];
    }
}
