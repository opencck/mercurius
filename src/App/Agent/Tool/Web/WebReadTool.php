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
 * spec: brief/internet.md#как-сделано-поиск-чтение-и-браузер — reading pages; the SSRF check repeats after every redirect
 */
final class WebReadTool implements Tool, GrantKeyed, ReadsOutside {
    public const NAME = 'web_read';
    private const MAX_REDIRECTS = 5;
    private const MAX_BYTES = 5_242_880;
    /**
     * Characters of a page in one answer; the rest by offset.
     *
     * ref: references/hermes-agent/tools/web_tools.py@e96221fa:L399-L443 — web_extract gives 15000 characters a page
     *      and stores the full text on disk; here the next window is read by offset from the cached page
     */
    public const WINDOW = 15_000;
    /**
     * How long a read page is kept for its next windows, and how many pages.
     *
     * ref: references/hermes-agent/tools/web_result_cache.py@e96221fa:L1-L2, L27 (DEFAULT_TTL_MINUTES) — 20 minutes,
     *      only a successful answer is kept
     */
    private const CACHE_SECONDS = 1200;
    private const CACHE_PAGES = 32;

    /** @var array<string, array{int, string, int, string, string}> url => expires, final url, status, title, text */
    private array $cache = [];

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
                'offset' => ['type' => 'integer', 'minimum' => 0, 'description' => t('tool.web_read.offset')],
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
        $offset = max(0, Args::int($args, 'offset') ?? 0);
        $permitted = $context->approved ? $this->internalHost($args) : null;
        // spec: brief/internet.md#как-сделано-поиск-чтение-и-браузер — a public page read lately is read on from memory; an internal one the owner
        //       allowed is fetched again each time
        $cached = $permitted === null ? ($this->cache[$url] ?? null) : null;
        if ($cached !== null && $cached[0] > time()) {
            return self::window($cached[1], $cached[2], $cached[3], $cached[4], $offset);
        }
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
            if ($permitted === null && $response->getStatus() === 200) {
                $this->keep(Args::string($args, 'url') ?? '', [time() + self::CACHE_SECONDS, $url, $response->getStatus(), $title, $text]);
            }
            return self::window($url, $response->getStatus(), $title, $text, $offset);
        }
        return ToolResult::error(sprintf(t('tool.web_read.slishkom_mnogo_perenapravleniy_bolshe'), self::MAX_REDIRECTS));
    }

    /** One window of the page's text from $offset, and where the next one starts. */
    private static function window(string $url, int $status, string $title, string $text, int $offset): ToolResult {
        $length = mb_strlen($text);
        if ($offset > 0 && $offset >= $length) {
            return ToolResult::error(sprintf(t('tool.web_read.past_end'), $length));
        }
        $part = mb_substr($text, $offset, self::WINDOW);
        $next = $offset + self::WINDOW;
        $head = sprintf("%s\nHTTP %d%s", $url, $status, $title === '' ? '' : t('tool.web_read.zagolovok') . $title);
        if ($length > self::WINDOW) {
            $head .= "\n" . sprintf(t('tool.web_read.window'), $offset + 1, min($length, $next), $length);
        }
        return ToolResult::ok($head . "\n\n" . $part . ($next < $length ? "\n\n" . sprintf(t('tool.web_read.more'), $length - $next, $next) : ''));
    }

    /** @param array{int, string, int, string, string} $page */
    private function keep(string $url, array $page): void {
        unset($this->cache[$url]);
        $this->cache[$url] = $page;
        $now = time();
        $this->cache = array_filter($this->cache, static fn(array $p): bool => $p[0] > $now);
        while (count($this->cache) > self::CACHE_PAGES) {
            array_shift($this->cache);
        }
    }
}
