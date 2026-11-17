<?php

declare(strict_types=1);

namespace OpenCCK\App\Agent\Tool;

use Amp\Http\Client\DelegateHttpClient;
use Amp\Http\Client\Request;
use Mercurius\Llm\ToolDefinition;
use OpenCCK\Domain\Agent\DangerClass;

use function Mercurius\I18n\t;

/**
 * An HTTP request. GET and HEAD to a public URL read; other methods change something outside and are irreversible
 * (they wait for the owner's approval), and so is any request to an internal address: the owner allows that host for
 * one call or for good, and only that host is let through. The result is untrusted data. Redirects are followed by hand, at most 5, and every
 * target is checked by AddressGuard again; the client's connector checks the address it actually dials as well.
 *
 * spec: BRIEF.md#инструменты — http_request; GET/HEAD read, internal addresses only with the owner's permission
 * ref: references/hermes-agent/website/docs/user-guide/security.md@e96221f:L819-L833 — redirect chains re-checked at every hop
 */
final class HttpRequestTool implements Tool, GrantKeyed, ReadsOutside {
    public const NAME = 'http_request';
    private const MAX_REDIRECTS = 5;
    private const METHODS = ['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE'];
    private const MAX_BODY = 1_048_576;

    public function __construct(
        private readonly DelegateHttpClient $http,
        private readonly AddressGuard $guard,
        /** secrets the request may carry by name; null — none */
        private readonly ?\OpenCCK\App\Vault\Vault $vault = null,
        /** @var (\Closure(AddressGuard): DelegateHttpClient)|null a client that dials through the given guard; null — $http */
        private readonly ?\Closure $clientFor = null,
    ) {}

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

    public function danger(array $args): DangerClass {
        // a secret leaves the agent only with the owner's card, whatever the method
        return in_array(self::method($args), ['GET', 'HEAD'], true) && self::secret($args) === null && $this->internalHost($args) === null
            ? DangerClass::Read
            : DangerClass::Irreversible;
    }

    public function grantKey(array $args): ?string {
        $secret = self::secret($args);
        if ($secret !== null) {
            // spec: BRIEF.md#серверы-и-сеть — a grant binds the secret to one host, as hermes binds a credential to hosts
            $host = parse_url(Args::string($args, 'url') ?? '', PHP_URL_HOST);
            return is_string($host) && $host !== '' ? sprintf('http-secret:%s %s', $secret, strtolower($host)) : null;
        }
        $method = self::method($args);
        if (in_array($method, ['GET', 'HEAD'], true)) {
            // spec: BRIEF.md#подтверждения-и-запреты — reading an internal address is granted per host
            $internal = $this->internalHost($args);
            return $internal === null ? null : 'addr:' . $internal;
        }
        $host = parse_url(Args::string($args, 'url') ?? '', PHP_URL_HOST);
        return is_string($host) && $host !== '' ? sprintf('http:%s %s', $method, strtolower($host)) : null;
    }

    public function describe(array $args): string {
        $secret = self::secret($args);
        return sprintf('HTTP %s %s', self::method($args), mb_substr(Args::string($args, 'url') ?? '', 0, 300))
            . ($secret === null ? '' : sprintf(t('tool.http_request.with_secret'), $secret, Args::string($args, 'secret_header') ?? 'Authorization: Bearer'))
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
        $secretName = self::secret($args);
        $secretValue = null;
        if ($secretName !== null) {
            $secretValue = $this->vault?->reveal($secretName, $context->principal);
            if ($secretValue === null) {
                $names = $this->vault === null ? [] : array_column($this->vault->names($context->principal), 'name');
                return ToolResult::error(sprintf(t('tool.http_request.no_secret'), $secretName, $names === [] ? '—' : implode(', ', $names)));
            }
            $header = trim(Args::string($args, 'secret_header') ?? '');
            $headers[$header === '' ? 'Authorization' : $header] = $header === '' ? 'Bearer ' . $secretValue : $secretValue;
        }

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
            // a secret goes only over https and only to the host the owner allowed: a redirect elsewhere is not followed
            if ($secretValue !== null) {
                $host = strtolower($parts['host']);
                if ($scheme !== 'https') {
                    return ToolResult::error(t('tool.http_request.secret_https_only'));
                }
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
            $request->setBodySizeLimit(PHP_INT_MAX);
            $response = $http->request($request, $context->cancellation);

            $location = $response->getHeader('location');
            // A request that changes something is not repeated at another address: the model decides.
            if (in_array($method, ['GET', 'HEAD'], true) && in_array($response->getStatus(), [301, 302, 303, 307, 308], true) && $location !== null) {
                $url = self::resolveLocation($url, $location);
                continue;
            }

            $text = sprintf("HTTP %d\ncontent-type: %s\n\n", $response->getStatus(), $response->getHeader('content-type') ?? '');
            $answer = $text . $this->readBody($response->getBody(), $context);
            return ToolResult::ok($secretValue === null ? $answer : str_replace($secretValue, '***', $answer));
        }
        return ToolResult::error(sprintf(t('tool.http_request.slishkom_mnogo_perenapravleniy_bolshe'), self::MAX_REDIRECTS));
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
