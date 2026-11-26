<?php

declare(strict_types=1);

namespace OpenCCK\Infrastructure\Llm\Antigravity;

use Amp\Http\Client\HttpClient;
use Amp\Http\Client\Request;
use Amp\TimeoutCancellation;
use Mercurius\Llm\ModelCatalog\ModelInfo;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RuntimeException;
use SensitiveParameter;
use Throwable;

/**
 * The module antigravity as the application sees it: models of the owner's Google Antigravity plan, served by the
 * official CLI in its own service. Here are the questions about the sign-in, the quota and the models; the model
 * calls go through the client of Chat Completions with the address of the same service. The sign-in lives in the
 * service — the application never holds it.
 *
 * spec: brief/models.md#google-по-подписке
 * spec: services/antigravity/BRIEF.md#http-интерфейс
 */
final class AntigravitySubscription {
    public const MODULE = 'antigravity';
    /** seconds an answer about the sign-in is reused: the service starts a process to learn it */
    private const STATUS_SECONDS = 30.0;

    /** @var array{float, ?array{usable: bool, auth: string, detail: string, cli: string, pending: bool}} */
    private array $status = [0.0, null];
    private readonly string $url;

    public function __construct(
        private readonly HttpClient $http,
        string $url,
        #[SensitiveParameter] private readonly string $token,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
        $this->url = rtrim($url, '/');
    }

    /**
     * What the service says about the CLI and the sign-in; null — the service does not answer.
     *
     * @return ?array{usable: bool, auth: string, detail: string, cli: string, pending: bool}
     */
    public function status(bool $fresh = false): ?array {
        $now = microtime(true);
        if (!$fresh && $now - $this->status[0] < self::STATUS_SECONDS) {
            return $this->status[1];
        }
        $data = $this->ask('GET', '/auth/status');
        $status = $data === null ? null : [
            'usable' => ($data['usable'] ?? false) === true,
            'auth' => self::text($data, 'auth'),
            'detail' => self::text($data, 'detail'),
            'cli' => self::text($data, 'cli'),
            'pending' => ($data['login_pending'] ?? false) === true,
        ];
        $this->status = [$now, $status];
        return $status;
    }

    /** The plan can serve a call now: the CLI works and the owner is signed in. */
    public function signedIn(): bool {
        $status = $this->status();
        return $status !== null && $status['usable'] && $status['auth'] === 'login';
    }

    /** The sign-in changed: the next question goes to the service. */
    public function forget(): void {
        $this->status = [0.0, null];
    }

    /**
     * Starts a sign-in and gives the link the owner opens and the seconds the CLI waits for the code.
     *
     * @return array{string, int}
     * @throws RuntimeException the service could not start it
     */
    public function login(): array {
        $data = $this->ask('POST', '/auth/login', null, 40.0);
        $url = $data === null ? '' : self::text($data, 'url');
        if (!str_starts_with($url, 'https://accounts.google.com/')) {
            throw new RuntimeException('The service gave no sign-in link');
        }
        /** @var mixed $wait */
        $wait = $data['wait_seconds'] ?? null;
        return [$url, is_int($wait) && $wait > 0 ? $wait : 60];
    }

    /** Passes the code to the CLI; true — the sign-in is done. */
    public function code(#[SensitiveParameter] string $code): bool {
        $data = $this->ask('POST', '/auth/code', ['code' => $code], 60.0);
        $this->forget();
        return $data !== null && self::text($data, 'auth') === 'login';
    }

    /**
     * The quota of the plan, asked from the CLI without a model call; null — it could not be learned.
     *
     * @return ?array{groups: list<array{name: string, buckets: list<array{window: string, remaining: float, resets_at: ?int}>}>, stale: bool}
     */
    public function quota(): ?array {
        $data = $this->ask('GET', '/usage', null, 30.0);
        /** @var mixed $raw */
        $raw = $data['groups'] ?? null;
        if ($data === null || !is_array($raw)) {
            return null;
        }
        $groups = [];
        /** @var mixed $group */
        foreach ($raw as $group) {
            if (!is_array($group)) {
                continue;
            }
            $buckets = [];
            /** @var mixed $bucket */
            foreach (is_array($group['buckets'] ?? null) ? $group['buckets'] : [] as $bucket) {
                if (!is_array($bucket)) {
                    continue;
                }
                /** @var mixed $left */
                $left = $bucket['remaining'] ?? null;
                /** @var mixed $reset */
                $reset = $bucket['resets_at'] ?? null;
                $buckets[] = ['window' => self::text($bucket, 'window'), 'remaining' => is_int($left) || is_float($left) ? (float) $left : 1.0, 'resets_at' => is_int($reset) ? $reset : null];
            }
            $groups[] = ['name' => self::text($group, 'name'), 'buckets' => $buckets];
        }
        return ['groups' => $groups, 'stale' => ($data['stale'] ?? false) === true];
    }

    /**
     * The models of the plan as the service lists them, with the price of nothing: a call by the plan is not billed
     * per token. The window is what one message to the CLI may hold, so the application compacts a history in time.
     *
     * @return list<ModelInfo>
     * @throws RuntimeException the service did not answer
     */
    public function models(): array {
        $data = $this->ask('GET', '/v1/models', null, 40.0);
        /** @var mixed $list */
        $list = $data['data'] ?? null;
        if ($data === null || !is_array($list)) {
            throw new RuntimeException('The module antigravity gave no list of models');
        }
        $models = [];
        /** @var mixed $item */
        foreach ($list as $item) {
            $id = is_array($item) ? self::text($item, 'id') : '';
            if (!is_array($item) || preg_match('~^[a-z0-9][a-z0-9.\-]{0,63}$~', $id) !== 1) {
                continue;
            }
            /** @var mixed $window */
            $window = $item['context_window'] ?? null;
            /** @var mixed $efforts */
            $efforts = $item['efforts'] ?? null;
            /** @var mixed $modalities */
            $modalities = $item['input'] ?? null;
            $input = [];
            /** @var mixed $modality */
            foreach (is_array($modalities) ? $modalities : [] as $modality) {
                if (is_string($modality)) {
                    $input[] = $modality;
                }
            }
            $models[] = new ModelInfo(
                $id,
                'Antigravity: ' . $id,
                is_int($window) && $window > 0 ? $window : null,
                0.0,
                0.0,
                $input === [] ? ['text'] : $input,
                ['text'],
                is_array($efforts) && $efforts !== [] ? ['tools', 'tool_choice', 'reasoning'] : ['tools', 'tool_choice'],
                null,
                0.0,
                0.0,
            );
        }
        return $models;
    }

    /**
     * @param non-empty-string $method
     * @param ?array<string, string> $body
     * @return ?array<array-key, mixed> the answer of the service; null — no answer, or a refusal
     */
    private function ask(string $method, string $path, ?array $body = null, float $seconds = 10.0): ?array {
        try {
            $request = new Request($this->url . $path, $method);
            $request->setHeader('authorization', 'Bearer ' . $this->token);
            if ($body !== null) {
                $request->setHeader('content-type', 'application/json');
                $request->setBody(json_encode($body, JSON_THROW_ON_ERROR));
            }
            $request->setTransferTimeout($seconds);
            $request->setInactivityTimeout($seconds);
            $request->setBodySizeLimit(1_000_000);
            $response = $this->http->request($request, new TimeoutCancellation($seconds + 5.0));
            $text = $response->getBody()->buffer();
            if ($response->getStatus() !== 200) {
                // the text of a refusal may quote what was sent: only the status goes to the journal
                $this->logger->info('The module antigravity refused a question', ['path' => $path, 'status' => $response->getStatus()]);
                return null;
            }
            /** @var mixed $data */
            $data = json_decode($text, true, 32, JSON_THROW_ON_ERROR);
            return is_array($data) ? $data : null;
        } catch (Throwable $e) {
            $this->logger->info('The module antigravity did not answer', ['path' => $path, 'error' => $e::class]);
            return null;
        }
    }

    /** @param array<array-key, mixed> $data */
    private static function text(array $data, string $key): string {
        /** @var mixed $value */
        $value = $data[$key] ?? null;
        return is_string($value) ? $value : '';
    }
}
