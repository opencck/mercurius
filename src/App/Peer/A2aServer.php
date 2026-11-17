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
    ) {
        $this->now = $now ?? static fn(): DateTimeImmutable => new DateTimeImmutable();
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
            return [null, 403, 'unknown agent ' . $fingerprint . ': its owner lets it in with /peer allow'];
        }
        return [$principal, 200, ''];
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
            'skills' => [['id' => 'general', 'name' => 'general', 'description' => 'Задачи владельца этого агента по его правилам и правам', 'tags' => ['general']]],
            'securitySchemes' => ['bearer' => ['type' => 'http', 'scheme' => 'bearer']],
            'security' => [['bearer' => []]],
            // Requests and answers are signed by ECDSA P-256 keys; the leader pins this key when it adds the agent.
            'mercurius' => ['publicKey' => $this->identity->publicKey, 'fingerprint' => $this->identity->fingerprint(), 'signing' => 'x-a2a-signature'],
        ];
    }

    /**
     * A JSON-RPC request of an authenticated agent.
     *
     * @param array<array-key, mixed> $request
     * @return array{int, array<string, mixed>} HTTP status and the JSON-RPC answer
     */
    public function handle(Principal $agent, array $request): array {
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
            'send' => [200, $this->send($agent, $id, $params, $v1)],
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
            $this->peers->updateTask($task->id, A2aTask::FAILED, null, 'Задача не закончилась за час.');
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
     * @return array<string, mixed>
     */
    private function send(Principal $agent, mixed $id, array $params, bool $v1): array {
        $message = isset($params['message']) && is_array($params['message']) ? $params['message'] : [];
        $messageId = isset($message['messageId']) && is_string($message['messageId']) ? trim($message['messageId']) : '';
        $text = self::text($message);
        if ($messageId === '' || strlen($messageId) > 200 || $text === '' || mb_strlen($text) > self::TEXT_MAX) {
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
            in_array($this->self, $path, true) => 'Цикл делегирования: задача уже проходила через этот экземпляр.',
            $depth >= self::MAX_DEPTH => sprintf('Цепочка делегирования длиннее %d.', self::MAX_DEPTH),
            $this->peers->contextTurns($agent->id, $contextId) >= self::MAX_TURNS => sprintf('В одном контексте не больше %d задач.', self::MAX_TURNS),
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
            $this->start($agent, $taskId, $contextId, $text);
        }
        $task = $this->peers->task($taskId);
        return $task === null ? self::error($id, self::ERR_TASK_NOT_FOUND, 'task not found') : self::result($id, $this->view($task), $v1);
    }

    private function start(Principal $agent, string $taskId, string $contextId, string $text): void {
        $policy = $this->policies->forPrincipal($agent);
        $conversation = $this->conversations->openConversation($agent->id, self::CHANNEL, 'a2a-' . $agent->id . '-' . $contextId);
        $this->conversations->setMeta($conversation, PromptBuilder::system($agent, ($this->now)()), $this->model);
        $run = $this->runs->start($conversation, $agent->id, $this->model, $policy->run);
        if ($run === null) {
            $this->peers->updateTask($taskId, A2aTask::REJECTED, null, 'В этом контексте уже идёт задача: дождитесь её конца.');
            return;
        }
        $this->transcript->appendUser($conversation, $run->id, sprintf(
            "Задача от агента «%s» по сети агентов (A2A). Ответь итогом для этого агента; если без уточнения не обойтись — начни ответ с %s и задай вопрос.\n\n%s",
            $agent->displayName ?? 'без имени',
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
                $this->peers->updateTask($task->id, A2aTask::FAILED, null, 'Внутренняя ошибка агента.');
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
        return [A2aTask::FAILED, 'Итога нет (' . ($final->stopReason?->label() ?? 'запуск не закончен') . ').'];
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
        $this->peers->updateTask($task->id, A2aTask::CANCELED, null, 'Отменено ведущим агентом.');
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
