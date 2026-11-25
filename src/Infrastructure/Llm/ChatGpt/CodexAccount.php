<?php

declare(strict_types=1);

namespace OpenCCK\Infrastructure\Llm\ChatGpt;

use Amp\Cancellation;
use Amp\Http\Client\HttpClient;
use Amp\Http\Client\Request;
use Closure;
use Mercurius\Llm\ErrorKind;
use Mercurius\Llm\LlmException;
use OpenCCK\App\Vault\Vault;
use OpenCCK\Domain\Entity\Member;
use SensitiveParameter;
use Throwable;

use function Amp\delay;

/**
 * The owner's ChatGPT subscription as a model provider: a sign-in by a device code (the owner opens a page and types
 * a code there; the agent never sees a password), its tokens kept in the vault, the access token refreshed before it
 * ends.
 *
 * spec: brief/models.md#провайдеры-моделей-openrouter-и-openai — Codex through the owner's ChatGPT subscription
 * ref: references/hermes-agent/hermes_cli/auth_codex.py@e96221fa:L1019-L1150 (device code, polling, exchange) and
 *      L503-L535 (refresh_codex_oauth_pure); hermes_cli/auth_constants.py@e96221fa:L74, L90-L92 — the same endpoints and
 *      client id; the tokens live in the vault instead of a file
 * ref: references/hermes-agent/agent/codex_headers.py@e96221fa:L57-L85 (codex_account_headers) — account headers from
 *      the token
 */
final class CodexAccount {
    public const ISSUER = 'https://auth.openai.com';
    public const CLIENT_ID = 'app_EMoamEEZ73f0CkXaXp7hrann';
    public const VERIFY_URL = self::ISSUER . '/codex/device';
    public const VAULT_NAME = 'chatgpt-codex-oauth';
    private const REFRESH_SKEW = 120;
    private const WAIT_SECONDS = 900;

    /** @param Closure(): ?Member $owner the owner holds the tokens in the vault */
    public function __construct(
        private readonly HttpClient $http,
        private readonly Vault $vault,
        private readonly Closure $owner,
    ) {}

    public function signedIn(): bool {
        return $this->tokens() !== null;
    }

    /**
     * The first step: a code for the owner to type at VERIFY_URL.
     *
     * @return array{code: string, id: string, interval: int}
     */
    public function start(?Cancellation $cancellation = null): array {
        $data = $this->post(self::ISSUER . '/api/accounts/deviceauth/usercode', json_encode(['client_id' => self::CLIENT_ID], JSON_THROW_ON_ERROR), 'application/json', $cancellation);
        $code = self::text($data, 'user_code');
        $id = self::text($data, 'device_auth_id');
        if ($code === '' || $id === '') {
            throw new LlmException(ErrorKind::Auth, 'ChatGPT did not give a device code');
        }
        /** @var mixed $given */
        $given = $data['interval'] ?? null;
        $interval = is_numeric($given) ? max(3, (int) $given) : 5;
        return ['code' => $code, 'id' => $id, 'interval' => $interval];
    }

    /** Waits until the owner signs in with the code (up to 15 minutes), then keeps the tokens. */
    public function finish(string $id, string $code, int $interval, ?Cancellation $cancellation = null): void {
        $deadline = time() + self::WAIT_SECONDS;
        $answer = [];
        while (time() < $deadline) {
            delay($interval, cancellation: $cancellation);
            $request = new Request(self::ISSUER . '/api/accounts/deviceauth/token', 'POST', json_encode(['device_auth_id' => $id, 'user_code' => $code], JSON_THROW_ON_ERROR));
            $request->setHeader('content-type', 'application/json');
            $response = $this->http->request($request, $cancellation);
            $body = $response->getBody()->buffer($cancellation);
            if ($response->getStatus() === 200) {
                $answer = self::json($body);
                break;
            }
            if (!in_array($response->getStatus(), [403, 404], true)) {
                throw new LlmException(ErrorKind::Auth, sprintf('ChatGPT sign-in answered HTTP %d', $response->getStatus()), $response->getStatus());
            }
        }
        $authorization = self::text($answer, 'authorization_code');
        $verifier = self::text($answer, 'code_verifier');
        if ($authorization === '' || $verifier === '') {
            throw new LlmException(ErrorKind::Auth, 'ChatGPT sign-in was not finished in time');
        }
        $tokens = $this->post('https://auth.openai.com/oauth/token', http_build_query([
            'grant_type' => 'authorization_code',
            'code' => $authorization,
            'redirect_uri' => self::ISSUER . '/deviceauth/callback',
            'client_id' => self::CLIENT_ID,
            'code_verifier' => $verifier,
        ]), 'application/x-www-form-urlencoded', $cancellation);
        $this->keep($tokens, null);
    }

    public function signOut(): bool {
        $owner = ($this->owner)();
        return $owner !== null && $this->vault->remove(self::VAULT_NAME, $owner);
    }

    /** The access token, refreshed when it ends within two minutes. */
    public function accessToken(?Cancellation $cancellation = null): string {
        $tokens = $this->tokens() ?? throw new LlmException(ErrorKind::Auth, 'ChatGPT is not signed in: the owner runs /codex login');
        if (self::expires($tokens['access']) > time() + self::REFRESH_SKEW) {
            return $tokens['access'];
        }
        $fresh = $this->post('https://auth.openai.com/oauth/token', http_build_query([
            'grant_type' => 'refresh_token',
            'refresh_token' => $tokens['refresh'],
            'client_id' => self::CLIENT_ID,
        ]), 'application/x-www-form-urlencoded', $cancellation);
        return $this->keep($fresh, $tokens['refresh']);
    }

    /**
     * What the Codex backend needs besides the token: the account and, for workspaces bound to a region, the residency.
     *
     * @return array<non-empty-string, string>
     */
    public static function accountHeaders(#[SensitiveParameter] string $token): array {
        /** @var mixed $auth */
        $auth = self::claims($token)['https://api.openai.com/auth'] ?? null;
        $auth = is_array($auth) ? $auth : [];
        $headers = [];
        $account = self::text($auth, 'chatgpt_account_id');
        if ($account !== '') {
            $headers['ChatGPT-Account-ID'] = $account;
        }
        $residency = trim(self::text($auth, 'chatgpt_data_residency') ?: self::text($auth, 'chatgpt_compute_residency'));
        if ($residency !== '') {
            $headers['x-openai-internal-codex-residency'] = $residency;
        }
        return $headers;
    }

    /** The end of a token by its "exp" claim; 0 — unknown, so it is refreshed. */
    public static function expires(#[SensitiveParameter] string $token): int {
        /** @var mixed $exp */
        $exp = self::claims($token)['exp'] ?? null;
        return is_int($exp) ? $exp : 0;
    }

    /** @return array{access: string, refresh: string}|null */
    private function tokens(): ?array {
        $owner = ($this->owner)();
        $kept = $owner === null ? null : $this->vault->reveal(self::VAULT_NAME, $owner);
        $data = $kept === null ? [] : self::json($kept);
        $access = self::text($data, 'access');
        $refresh = self::text($data, 'refresh');
        return $access === '' || $refresh === '' ? null : ['access' => $access, 'refresh' => $refresh];
    }

    /**
     * @param array<array-key, mixed> $tokens
     * @return string the access token kept
     */
    private function keep(array $tokens, ?string $refresh): string {
        $access = self::text($tokens, 'access_token');
        $next = self::text($tokens, 'refresh_token') ?: ($refresh ?? '');
        if ($access === '' || $next === '') {
            throw new LlmException(ErrorKind::Auth, 'ChatGPT gave no token');
        }
        $owner = ($this->owner)() ?? throw new LlmException(ErrorKind::Auth, 'No owner to keep the ChatGPT tokens');
        $this->vault->remove(self::VAULT_NAME, $owner);
        $this->vault->put(self::VAULT_NAME, 'token', json_encode(['access' => $access, 'refresh' => $next], JSON_THROW_ON_ERROR), $owner);
        return $access;
    }

    /** @return array<array-key, mixed> */
    private function post(string $url, string $body, string $type, ?Cancellation $cancellation): array {
        $request = new Request($url, 'POST', $body);
        $request->setHeader('content-type', $type);
        $request->setHeader('accept', 'application/json');
        try {
            $response = $this->http->request($request, $cancellation);
            $text = $response->getBody()->buffer($cancellation);
        } catch (Throwable $e) {
            throw new LlmException(ErrorKind::Network, 'ChatGPT sign-in is not reachable: ' . (new \ReflectionClass($e))->getShortName());
        }
        if ($response->getStatus() === 429) {
            throw new LlmException(ErrorKind::RateLimited, 'ChatGPT limits sign-in requests now, try again in a minute', 429);
        }
        if ($response->getStatus() !== 200) {
            $kind = in_array($response->getStatus(), [400, 401, 403], true) ? ErrorKind::Auth : ErrorKind::Server;
            throw new LlmException($kind, sprintf('ChatGPT sign-in answered HTTP %d: the owner runs /codex login again', $response->getStatus()), $response->getStatus());
        }
        return self::json($text);
    }

    /** @param array<array-key, mixed> $data */
    private static function text(array $data, string $key): string {
        /** @var mixed $value */
        $value = $data[$key] ?? null;
        return is_string($value) ? $value : '';
    }

    /** @return array<array-key, mixed> the claims of a JWT, unchecked: only to read the account and the end */
    private static function claims(#[SensitiveParameter] string $token): array {
        $parts = explode('.', $token);
        $payload = count($parts) >= 2 ? base64_decode(strtr($parts[1], '-_', '+/'), false) : false;
        return is_string($payload) ? self::json($payload) : [];
    }

    /** @return array<array-key, mixed> */
    private static function json(string $text): array {
        try {
            /** @var mixed $data */
            $data = json_decode($text, true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }
        return is_array($data) ? $data : [];
    }
}
