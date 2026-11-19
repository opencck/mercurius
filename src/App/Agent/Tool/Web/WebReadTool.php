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
use OpenCCK\App\Web\TextExtractor;
use OpenCCK\Domain\Agent\DangerClass;

use function Mercurius\I18n\t;

/**
 * A page of the web as readable text: GET, redirects followed and each one checked against internal addresses, the
 * page reduced to its text with its links. The result is untrusted data. An internal address waits for the owner, who
 * allows that host for one call or for good (the key is shared with http_request).
 *
 * spec: BRIEF.md#интернет-и-браузер — reading pages; the SSRF check repeats after every redirect
 */
final class WebReadTool implements Tool, GrantKeyed, ReadsOutside {
    public const NAME = 'web_read';
    private const MAX_REDIRECTS = 5;
    private const MAX_BYTES = 5_242_880;

    public function __construct(
        private readonly DelegateHttpClient $http,
        private readonly AddressGuard $guard,
        private readonly TextExtractor $extractor,
        /** @var (\Closure(AddressGuard): DelegateHttpClient)|null a client that dials through the given guard; null — $http */
        private readonly ?\Closure $clientFor = null,
    ) {}

    public function definition(): ToolDefinition {
        return new ToolDefinition(self::NAME, t('tool.web_read.otkryvaet_stranitsu_po_adresu_i')
            . t('tool.web_read.chitaet_i_docx_xlsx_tolko'), [
            'type' => 'object',
            'properties' => [
                'url' => ['type' => 'string', 'minLength' => 8, 'maxLength' => 2048],
            ],
            'required' => ['url'],
            'additionalProperties' => false,
        ]);
    }

    public function danger(array $args): DangerClass {
        return $this->internalHost($args) === null ? DangerClass::Read : DangerClass::Irreversible;
    }

    public function grantKey(array $args): ?string {
        $internal = $this->internalHost($args);
        return $internal === null ? null : 'addr:' . $internal;
    }

    public function describe(array $args): string {
        $internal = $this->internalHost($args);
        return 'web_read ' . mb_substr(Args::string($args, 'url') ?? '', 0, 300) . ($internal === null ? '' : sprintf(t('tool.address_internal'), $internal));
    }

    public function readsOutside(array $args): bool {
        return true;
    }

    /** @param array<string, mixed> $args */
    private function internalHost(array $args): ?string {
        $host = parse_url(Args::string($args, 'url') ?? '', PHP_URL_HOST);
        if (!is_string($host) || $host === '') {
            return null;
        }
        $host = strtolower(trim($host, '[]'));
        return $this->guard->internal($host) ? $host : null;
    }

    public function untrusted(): bool {
        return true;
    }

    public function timeout(): int {
        return 60;
    }

    public function execute(array $args, ToolContext $context): ToolResult {
        $url = Args::string($args, 'url') ?? '';
        $permitted = $context->approved ? $this->internalHost($args) : null;
        $guard = $permitted === null ? $this->guard : $this->guard->permitting($permitted);
        $http = $permitted === null || $this->clientFor === null ? $this->http : ($this->clientFor)($guard);
        for ($hop = 0; $hop <= self::MAX_REDIRECTS; $hop++) {
            $parts = parse_url($url);
            $scheme = strtolower(is_array($parts) ? ($parts['scheme'] ?? '') : '');
            if (!is_array($parts) || ($scheme !== 'http' && $scheme !== 'https') || !isset($parts['host'])) {
                return ToolResult::error(t('tool.web_read.podderzhivayutsya_tolko_adresa_http_i'));
            }
            $refusal = $guard->refusal($parts['host']);
            if ($refusal !== null) {
                return ToolResult::error($refusal);
            }
            $request = new Request($url, 'GET');
            $request->setHeader('user-agent', 'Mozilla/5.0 (compatible; Mercurius)');
            $request->setHeader('accept', 'text/html,application/xhtml+xml,text/plain;q=0.9,*/*;q=0.5');
            $request->setBodySizeLimit(self::MAX_BYTES);
            $response = $http->request($request, $context->cancellation);
            $location = $response->getHeader('location');
            if (in_array($response->getStatus(), [301, 302, 303, 307, 308], true) && $location !== null) {
                $response->getBody()->close();
                $url = HttpRequestTool::resolveLocation($url, $location);
                continue;
            }
            $body = $response->getBody()->buffer($context->cancellation);
            [$title, $text] = $this->extractor->extract($body, $response->getHeader('content-type') ?? '', $url);
            return ToolResult::ok(sprintf("%s\nHTTP %d%s\n\n%s", $url, $response->getStatus(), $title === '' ? '' : t('tool.web_read.zagolovok') . $title, $text));
        }
        return ToolResult::error(sprintf(t('tool.web_read.slishkom_mnogo_perenapravleniy_bolshe'), self::MAX_REDIRECTS));
    }
}
