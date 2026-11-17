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

use function Mercurius\I18n\t;

/**
 * Talks to a neighbouring agent over A2A v1.0: its card, a task, the state of a task. The transport is the one the
 * neighbour's card names: WebSocket (one connection, the keys proved once by the handshake) or HTTP (each request
 * signed). This agent presents to each neighbour the key chosen for it; an answer counts only when it comes from the
 * key pinned for the neighbour. Errors carry no token.
 *
 * spec: BRIEF.md#вход-по-websocket-и-несколько-ключей-решение-владельца-2026-11-17
 *
 * spec: BRIEF.md#сеть-агентов
 * ref: references/hermes-agent/plugins/platforms/a2a/tools.py@e96221fa:L146-L254 — discover, call and check a remote
 *      agent; here without streaming: the leader polls GetTask
 */
final class A2aClient {
    /** @var array<string, A2aSocket> open connections by neighbour and key */
    private array $sockets = [];

    /** @param (\Closure(string, string): A2aChannel)|null $connect opens a WebSocket by its url with the entrance token */
    public function __construct(
        private readonly HttpClient $http,
        private readonly AgentIdentity $identity,
        private readonly ?AgentKeys $keys = null,
        private readonly ?\Closure $connect = null,
        /** live connections with other agents, in both directions */
        private readonly ?A2aLinks $links = null,
        /** @var (\Closure(string): ((\Closure(array<array-key, mixed>): array<string, mixed>)|null))|null who answers the neighbour's requests by its key */
        private readonly ?\Closure $serveFor = null,
    ) {}

    /** ws — the neighbour's card names a WebSocket; http otherwise (neighbours added before the card said it). */
    public static function transport(Peer $peer): string {
        $meta = isset($peer->card['mercurius']) && is_array($peer->card['mercurius']) ? $peer->card['mercurius'] : [];
        return ($meta['transport'] ?? null) === 'ws' && isset($meta['ws']) && is_string($meta['ws']) ? 'ws' : 'http';
    }

    /** The WebSocket address the neighbour's card names; '' — none. */
    public static function socketUrl(Peer $peer): string {
        $card = $peer->card ?? [];
        /** @var mixed $meta */
        $meta = $card['mercurius'] ?? null;
        /** @var mixed $url */
        $url = is_array($meta) ? ($meta['ws'] ?? null) : null;
        return is_string($url) ? $url : '';
    }

    /** Opens a connection to the neighbour now, or finds a live one. */
    public function connect(Peer $peer, #[SensitiveParameter] string $token): A2aSocket {
        return $this->socket($peer, $token);
    }

    /**
     * A request over a live connection with an agent that is not a neighbour of this agent but connected here.
     *
     * @param array<string, mixed> $params
     * @return array<array-key, mixed>
     */
    public static function over(A2aSocket $socket, string $method, array $params, ?Cancellation $cancellation = null): array {
        return $socket->request($method, $params, $cancellation);
    }

    /** The key this agent presents to the neighbour. */
    public function identityFor(Peer $peer): AgentIdentity {
        return $this->keys?->get($peer->identity) ?? $this->identity;
    }

    /** @return array<array-key, mixed> */
    public function card(string $base, ?Cancellation $cancellation = null): array {
        return $this->request(new Request(rtrim($base, '/') . '/.well-known/agent-card.json'), $cancellation);
    }

    /**
     * @param list<string> $path
     * @param list<array{name: string, bytes: string}> $files
     * @return array<array-key, mixed> the task
     */
    public function send(Peer $peer, #[SensitiveParameter] string $token, string $messageId, string $text, int $depth, array $path, ?Cancellation $cancellation = null, array $files = []): array {
        return self::taskOf($this->call($peer, $token, 'SendMessage', self::message($messageId, $text, $depth, $path, $files), $cancellation));
    }

    /**
     * The parameters of SendMessage: the text and the files as parts of A2A v1 (raw bytes in base64, a file name).
     *
     * spec: BRIEF.md#личные-сообщения-участникам — not only text: files to other agents
     *
     * @param list<string> $path
     * @param list<array{name: string, bytes: string}> $files
     * @return array<string, mixed>
     */
    public static function message(string $messageId, string $text, int $depth, array $path, array $files = []): array {
        $parts = $text === '' ? [] : [['text' => $text, 'mediaType' => 'text/plain']];
        foreach ($files as $file) {
            $parts[] = ['raw' => base64_encode($file['bytes']), 'filename' => $file['name'], 'mediaType' => 'application/octet-stream'];
        }
        return [
            'message' => ['role' => 'ROLE_USER', 'messageId' => $messageId, 'parts' => $parts],
            'metadata' => ['mercurius' => ['depth' => $depth, 'path' => $path]],
        ];
    }

    /**
     * @param array<array-key, mixed> $result
     * @return array<array-key, mixed>
     */
    public static function taskOf(array $result): array {
        return isset($result['task']) && is_array($result['task']) ? $result['task'] : $result;
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
        if (self::transport($peer) === 'ws' && $this->connect !== null) {
            return $this->socket($peer, $token)->request($method, $params, $cancellation);
        }
        $identity = $this->identityFor($peer);
        $url = rtrim($peer->url, '/') . '/a2a';
        $body = json_encode(['jsonrpc' => '2.0', 'id' => bin2hex(random_bytes(4)), 'method' => $method, 'params' => $params], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        $timestamp = (string) time();
        $nonce = bin2hex(random_bytes(16));
        $path = parse_url($url, PHP_URL_PATH);
        $request = new Request($url, 'POST', $body);
        $request->setHeader('authorization', 'Bearer ' . $token);
        $request->setHeader('content-type', 'application/json');
        $request->setHeader('a2a-version', '1.0');
        $request->setHeader('x-a2a-key', base64_encode($identity->publicKey));
        $request->setHeader('x-a2a-timestamp', $timestamp);
        $request->setHeader('x-a2a-nonce', $nonce);
        $request->setHeader('x-a2a-signature', $identity->sign(AgentIdentity::requestData('POST', is_string($path) ? $path : '/a2a', $timestamp, $nonce, $body)));
        $data = $this->request($request, $cancellation, $peer->publicKey, $nonce);
        if (isset($data['error']) && is_array($data['error'])) {
            $message = isset($data['error']['message']) && is_string($data['error']['message']) ? $data['error']['message'] : 'error';
            throw new RuntimeException(t('peer.a2a_client.sosed_otkazal') . mb_substr($message, 0, 200));
        }
        return isset($data['result']) && is_array($data['result']) ? $data['result'] : throw new RuntimeException(t('peer.a2a_client.sosed_otvetil_bez_rezultata'));
    }

    /** The open connection to the neighbour under the key chosen for it; a new one when the old one closed. */
    private function socket(Peer $peer, #[SensitiveParameter] string $token): A2aSocket {
        $identity = $this->identityFor($peer);
        $peerFingerprint = AgentIdentity::fingerprintOf($peer->publicKey) ?? '';
        // spec: BRIEF.md#подключения-агентов-решение-владельца-2026-11-17 — any live connection with the neighbour serves,
        // whichever side opened it
        $live = $this->links?->to($peerFingerprint);
        if ($live !== null) {
            return $live;
        }
        $slot = $peer->name . " " . $identity->fingerprint();
        $open = $this->sockets[$slot] ?? null;
        if ($open !== null && $open->alive()) {
            return $open;
        }
        $url = self::socketUrl($peer);
        try {
            $channel = ($this->connect ?? throw new RuntimeException('no websocket'))($url, $token);
        } catch (RuntimeException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new RuntimeException(t('peer.a2a_client.sosed_ne_otvetil') . (new \ReflectionClass($e))->getShortName() . ').');
        }
        $socket = A2aSocket::open($channel, $identity, $peer->publicKey, 10.0, $this->serveFor === null ? null : ($this->serveFor)($peerFingerprint));
        $this->links?->add(A2aLinks::OUT, $peer->name, $peerFingerprint, $socket);
        return $this->sockets[$slot] = $socket;
    }

    /** @return array<array-key, mixed> */
    private function request(Request $request, ?Cancellation $cancellation, ?string $peerKey = null, string $nonce = ''): array {
        $request->setTransferTimeout(30);
        $request->setInactivityTimeout(30);
        try {
            $response = $this->http->request($request, $cancellation ?? new \Amp\NullCancellation());
            $body = $response->getBody()->buffer($cancellation, 1_048_576);
        } catch (Throwable $e) {
            throw new RuntimeException(t('peer.a2a_client.sosed_ne_otvetil') . (new \ReflectionClass($e))->getShortName() . ').');
        }
        if ($response->getStatus() === 404 && $peerKey !== null) {
            throw new A2aMoved(t('peer.a2a_client.moved'));
        }
        if ($peerKey !== null && !in_array($response->getStatus(), [401, 403], true)
            && !AgentIdentity::verify($peerKey, AgentIdentity::responseData($nonce, $body), $response->getHeader('x-a2a-signature') ?? '')) {
            throw new RuntimeException(t('peer.a2a_client.otvet_podpisan_ne_klyuchom_etogo'));
        }
        try {
            /** @var mixed $data */
            $data = json_decode($body, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new RuntimeException(sprintf(t('peer.a2a_client.sosed_otvetil_ne_json_http'), $response->getStatus()));
        }
        if (!is_array($data)) {
            throw new RuntimeException(t('peer.a2a_client.sosed_otvetil_ne_obektom'));
        }
        if ($response->getStatus() === 401 || $response->getStatus() === 403) {
            $message = isset($data['error']) && is_array($data['error']) && isset($data['error']['message']) && is_string($data['error']['message']) ? $data['error']['message'] : '';
            throw new RuntimeException(t('peer.a2a_client.sosed_ne_pustil') . mb_substr($message, 0, 200));
        }
        return $data;
    }
}
