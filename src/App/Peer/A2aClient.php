<?php

declare(strict_types=1);

namespace OpenCCK\App\Peer;

use Amp\Cancellation;
use Amp\Http\Client\HttpClient;
use Amp\Http\Client\Request;
use OpenCCK\Domain\Peer\Peer;
use RuntimeException;
use SensitiveParameter;
use Throwable;

/**
 * Talks to a neighbouring agent over A2A v1.0: its card, a task, the state of a task. Each request is signed by this
 * agent's key; an answer counts only when it is signed by the key pinned for the neighbour. Errors carry no token.
 *
 * spec: BRIEF.md#сеть-агентов
 * ref: references/hermes-agent/plugins/platforms/a2a/tools.py@e96221fa:L146-L254 — discover, call and check a remote
 *      agent; here without streaming: the leader polls GetTask
 */
final class A2aClient {
    public function __construct(
        private readonly HttpClient $http,
        private readonly AgentIdentity $identity,
    ) {}

    /** @return array<array-key, mixed> */
    public function card(string $base, ?Cancellation $cancellation = null): array {
        return $this->request(new Request(rtrim($base, '/') . '/.well-known/agent-card.json'), $cancellation);
    }

    /**
     * @param list<string> $path
     * @return array<array-key, mixed> the task
     */
    public function send(Peer $peer, #[SensitiveParameter] string $token, string $messageId, string $text, int $depth, array $path, ?Cancellation $cancellation = null): array {
        $result = $this->call($peer, $token, 'SendMessage', [
            'message' => ['role' => 'ROLE_USER', 'messageId' => $messageId, 'parts' => [['text' => $text, 'mediaType' => 'text/plain']]],
            'metadata' => ['mercurius' => ['depth' => $depth, 'path' => $path]],
        ], $cancellation);
        $task = isset($result['task']) && is_array($result['task']) ? $result['task'] : $result;
        return $task;
    }

    /** @return array<array-key, mixed> the task */
    public function get(Peer $peer, #[SensitiveParameter] string $token, string $taskId, ?Cancellation $cancellation = null): array {
        return $this->call($peer, $token, 'GetTask', ['id' => $taskId], $cancellation);
    }

    /**
     * The public key a card announces; null when it has none.
     *
     * @param array<array-key, mixed> $card
     */
    public static function cardKey(array $card): ?string {
        $key = isset($card['mercurius']) && is_array($card['mercurius']) && isset($card['mercurius']['publicKey']) && is_string($card['mercurius']['publicKey']) ? $card['mercurius']['publicKey'] : null;
        return $key === null || AgentIdentity::fingerprintOf($key) === null ? null : $key;
    }

    /** @param array<array-key, mixed> $task */
    public static function state(array $task): string {
        return isset($task['status']) && is_array($task['status']) && isset($task['status']['state']) && is_string($task['status']['state']) ? $task['status']['state'] : '';
    }

    /** @param array<array-key, mixed> $task */
    public static function answer(array $task): string {
        $message = isset($task['status']) && is_array($task['status']) && isset($task['status']['message']) && is_array($task['status']['message']) ? $task['status']['message'] : [];
        return A2aServer::text($message);
    }

    /**
     * @param array<string, mixed> $params
     * @return array<array-key, mixed>
     */
    private function call(Peer $peer, #[SensitiveParameter] string $token, string $method, array $params, ?Cancellation $cancellation): array {
        $url = rtrim($peer->url, '/') . '/a2a';
        $body = json_encode(['jsonrpc' => '2.0', 'id' => bin2hex(random_bytes(4)), 'method' => $method, 'params' => $params], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        $timestamp = (string) time();
        $nonce = bin2hex(random_bytes(16));
        $path = parse_url($url, PHP_URL_PATH);
        $request = new Request($url, 'POST', $body);
        $request->setHeader('authorization', 'Bearer ' . $token);
        $request->setHeader('content-type', 'application/json');
        $request->setHeader('a2a-version', '1.0');
        $request->setHeader('x-a2a-key', base64_encode($this->identity->publicKey));
        $request->setHeader('x-a2a-timestamp', $timestamp);
        $request->setHeader('x-a2a-nonce', $nonce);
        $request->setHeader('x-a2a-signature', $this->identity->sign(AgentIdentity::requestData('POST', is_string($path) ? $path : '/a2a', $timestamp, $nonce, $body)));
        $data = $this->request($request, $cancellation, $peer->publicKey, $nonce);
        if (isset($data['error']) && is_array($data['error'])) {
            $message = isset($data['error']['message']) && is_string($data['error']['message']) ? $data['error']['message'] : 'error';
            throw new RuntimeException('Сосед отказал: ' . mb_substr($message, 0, 200));
        }
        return isset($data['result']) && is_array($data['result']) ? $data['result'] : throw new RuntimeException('Сосед ответил без результата.');
    }

    /** @return array<array-key, mixed> */
    private function request(Request $request, ?Cancellation $cancellation, ?string $peerKey = null, string $nonce = ''): array {
        $request->setTransferTimeout(30);
        $request->setInactivityTimeout(30);
        try {
            $response = $this->http->request($request, $cancellation ?? new \Amp\NullCancellation());
            $body = $response->getBody()->buffer($cancellation, 1_048_576);
        } catch (Throwable $e) {
            throw new RuntimeException('Сосед не ответил (' . (new \ReflectionClass($e))->getShortName() . ').');
        }
        if ($peerKey !== null && !in_array($response->getStatus(), [401, 403], true)
            && !AgentIdentity::verify($peerKey, AgentIdentity::responseData($nonce, $body), $response->getHeader('x-a2a-signature') ?? '')) {
            throw new RuntimeException('Ответ подписан не ключом этого соседа — говорит кто-то другой.');
        }
        try {
            /** @var mixed $data */
            $data = json_decode($body, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new RuntimeException(sprintf('Сосед ответил не JSON (HTTP %d).', $response->getStatus()));
        }
        if (!is_array($data)) {
            throw new RuntimeException('Сосед ответил не объектом.');
        }
        if ($response->getStatus() === 401 || $response->getStatus() === 403) {
            $message = isset($data['error']) && is_array($data['error']) && isset($data['error']['message']) && is_string($data['error']['message']) ? $data['error']['message'] : '';
            throw new RuntimeException('Сосед не пустил: ' . mb_substr($message, 0, 200));
        }
        return $data;
    }
}
