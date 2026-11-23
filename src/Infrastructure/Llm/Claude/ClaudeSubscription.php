<?php

declare(strict_types=1);

namespace OpenCCK\Infrastructure\Llm\Claude;

use Amp\Http\Client\HttpClient;
use Amp\Http\Client\HttpClientBuilder;
use Amp\Http\Client\Interceptor\ModifyRequest;
use Amp\Http\Client\Request;
use Amp\TimeoutCancellation;
use Closure;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RuntimeException;
use SensitiveParameter;
use Throwable;

/**
 * The module claude-code as the application sees it: Claude by the owner's subscription, served by the official
 * Claude Code in its own service. Here are the questions about the sign-in and the plan; the model calls go through
 * the client of the Messages API with the address of the same service. The sign-in itself lives in the service — the
 * application never holds it; a token of `claude setup-token`, when the owner set one, goes with each request and is
 * kept by the service for that request only.
 *
 * spec: brief/models.md#claude-по-подписке
 * spec: brief/models.md#вход-в-подписку-claude
 */
final class ClaudeSubscription {
    public const MODULE = 'claude-code';
    /** the header the service takes the token of `claude setup-token` in */
    private const OAUTH_HEADER = 'x-claude-oauth-token';
    /** the owner's word about paid use beyond the plan, and the percent of a window to stop at */
    private const EXTRA_USAGE_HEADER = 'x-claude-extra-usage';
    private const RESERVE_HEADER = 'x-claude-extra-usage-reserve';
    /** seconds an answer about the sign-in is reused: the service starts a process to learn it */
    private const STATUS_SECONDS = 30.0;

    /** @var array{float, ?array{usable: bool, auth: string, plan: string, detail: string, cli: string, pending: bool}} */
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
     * An HTTP client for the service: each request carries what the owner set now — the token of the subscription
     * and the word about paid use. The service is on the internal network, so the client goes directly.
     *
     * @param Closure(): ?string $oauthToken the token of `claude setup-token`, when the owner set one
     * @param Closure(): string $extraUsage stop, notify or allow
     * @param Closure(): string $reserve the percent of a window to stop at; empty — none
     */
    public static function http(Closure $oauthToken, Closure $extraUsage, Closure $reserve): HttpClient {
        return (new HttpClientBuilder())
            ->retry(0)
            ->followRedirects(0)
            ->intercept(new ModifyRequest(static function (Request $request) use ($oauthToken, $extraUsage, $reserve): Request {
                $token = trim((string) $oauthToken());
                if ($token !== '') {
                    $request->setHeader(self::OAUTH_HEADER, $token);
                }
                $request->setHeader(self::EXTRA_USAGE_HEADER, $extraUsage());
                $percent = trim($reserve());
                if ($percent !== '') {
                    $request->setHeader(self::RESERVE_HEADER, $percent);
                }
                return $request;
            }))
            ->build();
    }

    /**
     * What the service says about the CLI and the sign-in; null — the service does not answer.
     *
     * @return ?array{usable: bool, auth: string, plan: string, detail: string, cli: string, pending: bool}
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
            'plan' => self::text($data, 'plan'),
            'detail' => self::text($data, 'detail'),
            'cli' => self::text($data, 'cli'),
            'pending' => ($data['login_pending'] ?? false) === true,
        ];
        $this->status = [$now, $status];
        return $status;
    }

    /** The subscription can serve a call now: the CLI works and somebody is signed in (or a token is set). */
    public function signedIn(): bool {
        $status = $this->status();
        return $status !== null && $status['usable'] && in_array($status['auth'], ['login', 'token'], true);
    }

    /** The sign-in changed: the next question goes to the service. */
    public function forget(): void {
        $this->status = [0.0, null];
    }

    /**
     * Starts a sign-in and gives the link the owner opens.
     *
     * @throws RuntimeException the service could not start it
     */
    public function login(): string {
        $data = $this->ask('POST', '/auth/login', null, 30.0);
        $url = $data === null ? '' : self::text($data, 'url');
        if (!str_starts_with($url, 'https://')) {
            throw new RuntimeException('The service gave no sign-in link');
        }
        return $url;
    }

    /** Passes the code from the page to the CLI; true — the sign-in is done. */
    public function code(#[SensitiveParameter] string $code): bool {
        $data = $this->ask('POST', '/auth/login/code', ['code' => $code], 60.0);
        $this->forget();
        return $data !== null && in_array(self::text($data, 'auth'), ['login', 'token'], true);
    }

    public function logout(): void {
        $this->ask('POST', '/auth/logout', null, 30.0);
        $this->forget();
    }

    /**
     * The usage of the plan, asked from the CLI without a model call; null — it could not be learned.
     *
     * @return ?array{limits: array<array-key, mixed>, using: bool, resetsAt: ?int}
     */
    public function usage(): ?array {
        $data = $this->ask('GET', '/usage', null, 30.0);
        /** @var mixed $limits */
        $limits = $data['rate_limits'] ?? null;
        if ($data === null || !is_array($limits)) {
            return null;
        }
        /** @var mixed $extra */
        $extra = $data['extra_usage'] ?? null;
        /** @var mixed $reset */
        $reset = is_array($extra) ? ($extra['resets_at'] ?? null) : null;
        return ['limits' => $limits, 'using' => is_array($extra) && ($extra['using'] ?? false) === true, 'resetsAt' => is_int($reset) ? $reset : null];
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
                $this->logger->info('The module claude-code refused a question', ['path' => $path, 'status' => $response->getStatus()]);
                return null;
            }
            /** @var mixed $data */
            $data = json_decode($text, true, 32, JSON_THROW_ON_ERROR);
            return is_array($data) ? $data : null;
        } catch (Throwable $e) {
            $this->logger->info('The module claude-code did not answer', ['path' => $path, 'error' => $e::class]);
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
