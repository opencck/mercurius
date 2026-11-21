<?php

declare(strict_types=1);

namespace OpenCCK\App\Agent\Tool\Web;

use Amp\Http\Client\DelegateHttpClient;
use Amp\Http\Client\Request;
use Mercurius\Llm\ToolDefinition;
use OpenCCK\App\Agent\Tool\Args;
use OpenCCK\App\Agent\Tool\GrantKeyed;
use OpenCCK\App\Agent\Tool\Guard\AddressGuard;
use OpenCCK\App\Agent\Tool\ReadsOutside;
use OpenCCK\App\Agent\Tool\Tool;
use OpenCCK\App\Agent\Tool\ToolContext;
use OpenCCK\App\Agent\Tool\ToolResult;
use OpenCCK\Domain\Agent\DangerClass;

use function Mercurius\I18n\t;

/**
 * An HTTP request. GET and HEAD to a public URL read; other methods change something outside and are irreversible
 * (they wait for the owner's approval), and so is any request to an internal address: the owner allows that host for
 * one call or for good, and only that host is let through. The result is untrusted data. Redirects are followed by hand, at most 5, and every
 * target is checked by AddressGuard again; the client's connector checks the address it actually dials as well.
 *
 * spec: brief/agent-loop.md#инструменты — http_request; GET/HEAD read, internal addresses only with the owner's permission
 * ref: references/hermes-agent/website/docs/user-guide/security.md@e96221fa:L819-L833 — redirect chains re-checked at every hop
 */
final class HttpRequestTool implements Tool, GrantKeyed, \OpenCCK\App\Agent\Tool\WideGrant, ReadsOutside, \OpenCCK\App\Agent\Tool\PreparesInConversation, \OpenCCK\App\Agent\Tool\DecidedByParticipant {
    /** set by the code when a secret of the project is used: who gave it and to which project; a model cannot set them */
    private const SECRET_OWNER = 'secret_owner';
    private const SECRET_PROJECT = 'secret_project';
    public const NAME = 'http_request';
    private const MAX_REDIRECTS = 5;
    private const METHODS = ['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE'];
    private const MAX_BODY = 1_048_576;
    /** {{secret:name}} in the body or a header value: the code puts the value in, the model never sees it */
    private const PLACEHOLDER = '~\{\{secret:([^{}\s]{1,100})\}\}~';
    /** where the token of a page's forms is kept next to the cookies of its host */
    private const CSRF = 'csrf:';
    /** <meta name="csrf-token" content="…"> — the convention of 3x-ui, Laravel, Rails */
    private const CSRF_META = '~<meta\s+name=["\']csrf-token["\']\s+content=["\']([^"\'<>\s]{8,512})["\']~i';
    /** how long a cookie without its own expiry, or with a longer one, is kept */
    private const COOKIE_DAYS = 30;

    private readonly \OpenCCK\Domain\Repository\HttpCookieRepository $cookies;

    public function __construct(
        private readonly DelegateHttpClient $http,
        private readonly AddressGuard $guard,
        /** secrets the request may carry by name; null — none */
        private readonly ?\OpenCCK\App\Vault\Vault $vault = null,
        /** @var (\Closure(AddressGuard): DelegateHttpClient)|null a client that dials through the given guard; null — $http */
        private readonly ?\Closure $clientFor = null,
        ?\OpenCCK\Domain\Repository\HttpCookieRepository $cookies = null,
        /** @var (\Closure(ToolContext): string)|null whose cookies a call uses: a project, else the participant ("p<id>") */
        private readonly ?\Closure $cookieScope = null,
        /** @var (\Closure(int, \OpenCCK\Domain\Entity\Principal): ?int)|null the project of a conversation where the participant may change things; null — no secrets of projects */
        private readonly ?\Closure $projectOf = null,
        private readonly ?\OpenCCK\Domain\Repository\PrincipalRepository $principals = null,
    ) {
        $this->cookies = $cookies ?? new \OpenCCK\Infrastructure\Repository\InMemoryHttpCookieRepository();
    }

    public function definition(): ToolDefinition {
        return new ToolDefinition(self::NAME, t('tool.http_request.http_zapros_k_publichnomu_adresu')
            . t('tool.http_request.content_type_i_telo_do'), [
            'type' => 'object',
            'properties' => [
                'url' => ['type' => 'string', 'maxLength' => 2048, 'description' => t('tool.http_request.adres_http_ili_https')],
                'method' => ['type' => 'string', 'enum' => self::METHODS, 'description' => t('tool.http_request.po_umolchaniyu_get_ostalnye_metody')],
                'body' => ['type' => 'string', 'maxLength' => 1048576, 'description' => t('tool.http_request.telo_zaprosa_dlya_post_put')],
                'headers' => ['type' => 'object', 'description' => t('tool.http_request.dopolnitelnye_zagolovki_imya_stroka')],
                'secret' => ['type' => 'string', 'maxLength' => 100, 'description' => t('tool.http_request.secret_about')],
                'secret_header' => ['type' => 'string', 'maxLength' => 100, 'description' => t('tool.http_request.secret_header_about')],
            ],
            'required' => ['url'],
            'additionalProperties' => false,
        ]);
    }

    /**
     * A secret that is not the caller's own but was given to the project of this chat for this site: the call is
     * marked with who gave it, so the card goes to that participant and the value is taken as the project's.
     *
     * spec: brief/participants.md#ресурсы-проекта
     */
    public function prepareIn(array $args, \OpenCCK\Domain\Entity\Principal $principal, int $conversationId, \Amp\Cancellation $cancellation): array {
        unset($args[self::SECRET_OWNER], $args[self::SECRET_PROJECT]);
        $vault = $this->vault;
        $project = $this->projectOf === null || $vault === null ? null : ($this->projectOf)($conversationId, $principal);
        $host = parse_url(Args::string($args, 'url') ?? '', PHP_URL_HOST);
        if ($vault === null || $project === null || !is_string($host) || $host === '') {
            return $args;
        }
        foreach (self::secrets($args) as $name) {
            $author = $vault->readable($name, $principal) ? null : $vault->sharedWith($name, $project, $host);
            if ($author !== null) {
                $args[self::SECRET_OWNER] = $author;
                $args[self::SECRET_PROJECT] = $project;
            }
        }
        return $args;
    }

    public function decider(array $args): ?\OpenCCK\Domain\Entity\Principal {
        return isset($args[self::SECRET_OWNER]) && is_int($args[self::SECRET_OWNER]) ? $this->principals?->find($args[self::SECRET_OWNER]) : null;
    }

    public function danger(array $args): DangerClass {
        // a secret leaves the agent only with the owner's card, whatever the method
        return in_array(self::method($args), ['GET', 'HEAD'], true) && self::secrets($args) === [] && $this->internalHost($args) === null
            ? DangerClass::Read
            : DangerClass::Irreversible;
    }

    /** Any request without secrets to this host; a request with a secret keeps its own grant "secret + host". */
    public function wideGrant(array $args): ?array {
        $host = parse_url(Args::string($args, 'url') ?? '', PHP_URL_HOST);
        if (self::secrets($args) !== [] || !is_string($host) || $host === '' || in_array(self::method($args), ['GET', 'HEAD'], true)) {
            return null;
        }
        return ['http-any:' . strtolower($host), sprintf(t('agent.grants.wide_http'), strtolower($host))];
    }

    public function grantKey(array $args): ?string {
        $secrets = self::secrets($args);
        if ($secrets !== []) {
            // spec: brief/servers.md#серверы-и-сеть — a grant binds the secrets to one host, as hermes binds a credential to hosts
            $host = parse_url(Args::string($args, 'url') ?? '', PHP_URL_HOST);
            return is_string($host) && $host !== '' ? sprintf('http-secret:%s %s', implode(',', $secrets), strtolower($host)) : null;
        }
        $method = self::method($args);
        if (in_array($method, ['GET', 'HEAD'], true)) {
            // spec: brief/agent-loop.md#подтверждения-и-запреты — reading an internal address is granted per host
            $internal = $this->internalHost($args);
            return $internal === null ? null : 'addr:' . $internal;
        }
        $host = parse_url(Args::string($args, 'url') ?? '', PHP_URL_HOST);
        return is_string($host) && $host !== '' ? sprintf('http:%s %s', $method, strtolower($host)) : null;
    }

    public function describe(array $args): string {
        $secret = self::secret($args);
        $inside = array_values(array_diff(self::secrets($args), $secret === null ? [] : [$secret]));
        return sprintf('HTTP %s %s', self::method($args), mb_substr(Args::string($args, 'url') ?? '', 0, 300))
            . ($secret === null ? '' : sprintf(t('tool.http_request.with_secret'), $secret, Args::string($args, 'secret_header') ?? 'Authorization: Bearer'))
            . ($inside === [] ? '' : sprintf(t('tool.http_request.with_secrets_inside'), implode('», «', $inside)))
            . (($internal = $this->internalHost($args)) === null ? '' : sprintf(t('tool.address_internal'), $internal));
    }

    /** @param array<string, mixed> $args the host of the URL when it is internal; null — public or not an URL */
    private function internalHost(array $args): ?string {
        $host = parse_url(Args::string($args, 'url') ?? '', PHP_URL_HOST);
        if (!is_string($host) || $host === '') {
            return null;
        }
        $host = strtolower(trim($host, '[]'));
        return $this->guard->internal($host) ? $host : null;
    }

    /**
     * Every secret a call uses: the one for a header and those named by {{secret:name}} in the body and header values;
     * sorted, each once.
     *
     * @param array<string, mixed> $args
     * @return list<string>
     */
    private static function secrets(array $args): array {
        $names = [];
        $secret = self::secret($args);
        if ($secret !== null) {
            $names[] = $secret;
        }
        foreach ([Args::string($args, 'body') ?? '', ...array_values(Args::stringMap($args, 'headers'))] as $text) {
            if (preg_match_all(self::PLACEHOLDER, $text, $found) > 0) {
                $names = [...$names, ...$found[1]];
            }
        }
        $names = array_values(array_unique($names));
        sort($names);
        return $names;
    }

    /** @param array<string, mixed> $args */
    private static function secret(array $args): ?string {
        $name = trim(Args::string($args, 'secret') ?? '');
        return $name === '' ? null : $name;
    }

    /**
     * @param array<string, mixed> $args
     * @return 'GET'|'HEAD'|'POST'|'PUT'|'PATCH'|'DELETE'
     */
    private static function method(array $args): string {
        return match (Args::string($args, 'method')) {
            'HEAD' => 'HEAD',
            'POST' => 'POST',
            'PUT' => 'PUT',
            'PATCH' => 'PATCH',
            'DELETE' => 'DELETE',
            default => 'GET',
        };
    }

    public function readsOutside(array $args): bool {
        return true;
    }

    public function untrusted(): bool {
        return true;
    }

    public function timeout(): int {
        return 30;
    }

    public function execute(array $args, ToolContext $context): ToolResult {
        $url = Args::string($args, 'url') ?? '';
        $method = self::method($args);
        $body = Args::string($args, 'body');
        $headers = Args::stringMap($args, 'headers');
        // ref: references/hermes-agent/agent/proxy_sources/iron_proxy.py@e96221fa:L1-L8, L63-L77 — the agent never holds the
        //      credential, it is put into the header on the way out and bound to hosts; hermes binds by configuration
        //      behind an egress proxy, here the value is put in by the code and the owner's grant binds it to a host
        /** @var array<string, string> $values name => value of every secret of the call */
        $values = [];
        foreach (self::secrets($args) as $name) {
            $value = $this->vault?->reveal($name, $context->principal);
            // spec: brief/participants.md#ресурсы-проекта — a secret of the project, for its site, while the caller still works there
            $project = isset($args[self::SECRET_PROJECT]) && is_int($args[self::SECRET_PROJECT]) ? $args[self::SECRET_PROJECT] : null;
            $host = parse_url($url, PHP_URL_HOST);
            if ($value === null && $project !== null && is_string($host) && $this->projectOf !== null && ($this->projectOf)($context->conversationId, $context->principal) === $project) {
                $value = $this->vault?->revealShared($name, $project, $host);
            }
            if ($value === null) {
                $names = $this->vault === null ? [] : array_column($this->vault->names($context->principal), 'name');
                return ToolResult::error(sprintf(t('tool.http_request.no_secret'), $name, $names === [] ? '—' : implode(', ', $names)));
            }
            $values[$name] = $value;
        }
        // spec: brief/servers.md#вход-по-логину-и-паролю — a login form: secrets go into the body by name, escaped for
        //       its type (a form gets url-encoding, JSON — a string escape)
        $type = '';
        foreach ($headers as $name => $value) {
            if (strtolower($name) === 'content-type') {
                $type = strtolower($value);
            }
        }
        if ($body !== null) {
            $body = self::fill($body, $values, $type);
        }
        foreach ($headers as $name => $value) {
            $headers[$name] = self::fill($value, $values, '');
        }
        $secretName = self::secret($args);
        if ($secretName !== null) {
            $header = trim(Args::string($args, 'secret_header') ?? '');
            $headers[$header === '' ? 'Authorization' : $header] = $header === '' ? 'Bearer ' . $values[$secretName] : $values[$secretName];
        }
        $scope = $this->cookieScope === null ? 'p' . $context->principal->id : ($this->cookieScope)($context);

        // the owner allowed this call: its internal host, and no other, is let through
        $permitted = $context->approved ? $this->internalHost($args) : null;
        $guard = $permitted === null ? $this->guard : $this->guard->permitting($permitted);
        $http = $permitted === null || $this->clientFor === null ? $this->http : ($this->clientFor)($guard);
        $secretHost = null;
        for ($hop = 0; $hop <= self::MAX_REDIRECTS; $hop++) {
            $parts = parse_url($url);
            $scheme = strtolower($parts['scheme'] ?? '');
            if (($scheme !== 'http' && $scheme !== 'https') || !isset($parts['host'])) {
                return ToolResult::error(t('tool.http_request.podderzhivayutsya_tolko_adresa_http_i'));
            }
            // a secret goes only to the host the owner allowed (over http too — the owner's decision): a redirect
            // elsewhere is not followed
            $host = strtolower($parts['host']);
            if ($values !== []) {
                if ($secretHost !== null && $host !== $secretHost) {
                    return ToolResult::ok(sprintf(t('tool.http_request.secret_redirect_stopped'), $host));
                }
                $secretHost = $host;
            }
            $refusal = $guard->refusal($parts['host']);
            if ($refusal !== null) {
                return ToolResult::error($refusal);
            }

            $request = new Request($url, $method, $body ?? '');
            foreach ($headers as $name => $value) {
                $request->setHeader($name, $value);
            }
            // ref: references/hermes-agent/agent/redact_headers.py@e96221fa:L72-L79 — cookies carry sessions and are
            //      hidden from what the model reads; here they are not shown at all and live in the jar of the scope
            $jar = $this->cookies->cookies($scope, $host);
            if ($jar !== [] && !$request->hasHeader('cookie')) {
                $request->setHeader('cookie', implode('; ', array_map(static fn(string $n, string $v): string => $n . '=' . $v, array_keys($jar), $jar)));
            }
            // spec: brief/servers.md#секрет-в-http-запросе — a model shortens a long token when it copies it
            //       (QEfxnI…XCsQ), so the token a page of this host gave goes with a change by the code itself
            if (!in_array($method, ['GET', 'HEAD'], true) && !$request->hasHeader('x-csrf-token')) {
                $csrf = $this->cookies->cookies($scope, self::CSRF . $host)['token'] ?? null;
                if ($csrf !== null) {
                    $request->setHeader('x-csrf-token', $csrf);
                }
            }
            $request->setBodySizeLimit(PHP_INT_MAX);
            $response = $http->request($request, $context->cancellation);
            foreach ($response->getHeaderArray('set-cookie') as $line) {
                $cookie = self::cookie($line);
                if ($cookie !== null) {
                    $this->cookies->keep($scope, $host, ...$cookie);
                }
            }

            $location = $response->getHeader('location');
            // A request that changes something is not repeated at another address: the model decides.
            if (in_array($method, ['GET', 'HEAD'], true) && in_array($response->getStatus(), [301, 302, 303, 307, 308], true) && $location !== null) {
                $url = self::resolveLocation($url, $location);
                continue;
            }

            $text = sprintf("HTTP %d\ncontent-type: %s\n\n", $response->getStatus(), $response->getHeader('content-type') ?? '');
            $read = $this->readBody($response->getBody(), $context);
            if (preg_match(self::CSRF_META, $read, $token) === 1) {
                $this->cookies->keep($scope, self::CSRF . $host, 'token', html_entity_decode($token[1], ENT_QUOTES | ENT_HTML5), time() + self::COOKIE_DAYS * 86400);
            }
            $answer = $text . $read;
            return ToolResult::ok(self::mask($answer, $values));
        }
        return ToolResult::error(sprintf(t('tool.http_request.slishkom_mnogo_perenapravleniy_bolshe'), self::MAX_REDIRECTS));
    }

    /**
     * Puts the values of secrets in place of their {{secret:name}}, escaped for the type of the text.
     *
     * @param array<string, string> $values
     */
    private static function fill(string $text, array $values, string $type): string {
        return preg_replace_callback(self::PLACEHOLDER, static function (array $m) use ($values, $type): string {
            $value = $values[$m[1]] ?? '';
            if (str_contains($type, 'x-www-form-urlencoded')) {
                return rawurlencode($value);
            }
            if (str_contains($type, 'json')) {
                return substr(json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 1, -1);
            }
            return $value;
        }, $text) ?? $text;
    }

    /**
     * The answer without the values of the secrets, in any of the spellings they were sent in.
     *
     * @param array<string, string> $values
     */
    private static function mask(string $answer, array $values): string {
        foreach ($values as $value) {
            if (strlen($value) < 4) {
                continue;
            }
            $spellings = array_unique([$value, rawurlencode($value), urlencode($value), substr(json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 1, -1)]);
            $answer = str_replace($spellings, '***', $answer);
        }
        return $answer;
    }

    /**
     * A Set-Cookie line as [name, value, expires]; a past expiry or Max-Age=0 removes the cookie, a cookie without an
     * expiry or with a far one is kept for COOKIE_DAYS.
     *
     * @return array{string, string, int}|null
     */
    private static function cookie(string $line): ?array {
        $parts = explode(';', $line);
        $pair = explode('=', trim(array_shift($parts)), 2);
        $name = trim($pair[0]);
        if ($name === '' || !isset($pair[1]) || preg_match('~^[!#$%&\'*+.^_`|\~0-9A-Za-z-]{1,100}$~', $name) !== 1) {
            return null;
        }
        $value = trim($pair[1]);
        if (strlen($value) > 4096) {
            return null;
        }
        $now = time();
        $longest = $now + self::COOKIE_DAYS * 86400;
        $expires = $longest;
        foreach ($parts as $attribute) {
            $kv = explode('=', trim($attribute), 2);
            $key = strtolower(trim($kv[0]));
            $given = trim($kv[1] ?? '');
            if ($key === 'max-age' && preg_match('~^-?\d{1,12}$~', $given) === 1) {
                $expires = $now + (int) $given;
                break;
            }
            if ($key === 'expires' && ($at = strtotime($given)) !== false) {
                $expires = $at;
            }
        }
        return [$name, $value, min($expires, $longest)];
    }

    private function readBody(\Amp\ByteStream\ReadableStream $body, ToolContext $context): string {
        $data = '';
        while (($chunk = $body->read($context->cancellation)) !== null) {
            $data .= $chunk;
            if (strlen($data) > self::MAX_BODY) {
                $body->close();
                $data = mb_strcut($data, 0, self::MAX_BODY, 'UTF-8');
                return $this->text($data) . sprintf(t('tool.http_request.otvet_obrezan_bolshe_bayt'), self::MAX_BODY);
            }
        }
        return $this->text($data);
    }

    private function text(string $data): string {
        return mb_check_encoding($data, 'UTF-8') ? $data : sprintf(t('tool.http_request.dvoichnye_dannye_bayt'), strlen($data));
    }

    public static function resolveLocation(string $base, string $location): string {
        if (preg_match('#^https?://#i', $location) === 1) {
            return $location;
        }
        $parts = parse_url($base);
        $origin = ($parts['scheme'] ?? 'https') . '://' . ($parts['host'] ?? '') . (isset($parts['port']) ? ':' . $parts['port'] : '');
        if (str_starts_with($location, '//')) {
            return ($parts['scheme'] ?? 'https') . ':' . $location;
        }
        if (str_starts_with($location, '/')) {
            return $origin . $location;
        }
        $path = $parts['path'] ?? '/';
        return $origin . substr($path, 0, (int) strrpos($path, '/') + 1) . $location;
    }
}
