<?php

declare(strict_types=1);

namespace OpenCCK\App\Mcp;

use Amp\Cancellation;
use Amp\CompositeCancellation;
use Amp\Http\Client\HttpClient;
use Amp\Http\Client\Request;
use Amp\TimeoutCancellation;
use Closure;
use Psr\Log\LoggerInterface;
use Throwable;

use function Mercurius\I18n\t;

/**
 * The package of an MCP server started by npx or uvx is looked up in OSV before the owner's approval takes effect:
 * a known malware advisory (MAL-*) stops it. Ordinary vulnerabilities are not a reason to stop, and a failed lookup
 * lets the package go — the owner approves the server anyway, the check only catches a known bad one.
 *
 * spec: brief/skills-mcp.md#mcp-серверы-подключение-и-доверие — the check of MCP packages
 * ref: references/hermes-agent/tools/osv_check.py@e96221fa:L19-L36, L166-L269 (check_package_for_malware,
 *      _parse_package_from_args, _query_osv) — the same endpoint, runners, parsing of npm and PyPI names, MAL-* only,
 *      a 10 s lookup, a verdict kept for an hour, a failure not kept. hermes also keeps the verdicts on disk across
 *      restarts; here a check runs only on /mcp on, so the memory of one process is enough
 */
final class OsvCheck {
    private const ENDPOINT = 'https://api.osv.dev/v1/query';
    private const TIMEOUT = 10.0;
    private const KEEP = 3600.0;
    private const MAX_ENTRIES = 256;
    private const BODY_LIMIT = 2_000_000;
    private const ECOSYSTEMS = ['npx' => 'npm', 'npx.cmd' => 'npm', 'uvx' => 'PyPI', 'uvx.exe' => 'PyPI', 'pipx' => 'PyPI'];

    /** @var array<string, array{float, ?string}> ecosystem|package|version => until, verdict */
    private array $verdicts = [];
    /** @var Closure(): float */
    private readonly Closure $clock;

    /** @param (Closure(): float)|null $clock seconds now, for tests */
    public function __construct(
        private readonly HttpClient $http,
        private readonly LoggerInterface $logger,
        ?Closure $clock = null,
    ) {
        $this->clock = $clock ?? static fn(): float => microtime(true);
    }

    /** Why the package of this command may not run; null — it may (or it is not an npx/uvx package). */
    public function check(string $command, Cancellation $cancellation): ?string {
        $package = self::package($command);
        if ($package === null) {
            return null;
        }
        [$ecosystem, $name, $version] = $package;
        $key = $ecosystem . '|' . $name . '|' . ($version ?? '');
        $now = ($this->clock)();
        $kept = $this->verdicts[$key] ?? null;
        if ($kept !== null && $kept[0] > $now) {
            return $kept[1];
        }
        try {
            $malware = $this->query($ecosystem, $name, $version, $cancellation);
        } catch (\Amp\CancelledException $e) {
            if ($cancellation->isRequested()) {
                throw $e;
            }
            $this->logger->notice('OSV lookup timed out; the MCP package goes', ['package' => $name]);
            return null;
        } catch (Throwable $e) {
            $this->logger->notice('OSV lookup failed; the MCP package goes', ['package' => $name, 'error' => $e::class]);
            return null;
        }
        $verdict = null;
        if ($malware !== []) {
            $lines = array_map(static fn(array $v): string => $v['id'] . ($v['summary'] === '' ? '' : ' — ' . mb_substr($v['summary'], 0, 100)), array_slice($malware, 0, 3));
            $verdict = sprintf(t('mcp.osv.blocked'), $name, $ecosystem, implode('; ', $lines));
        }
        if (count($this->verdicts) >= self::MAX_ENTRIES) {
            $this->verdicts = array_filter($this->verdicts, static fn(array $v): bool => $v[0] > $now);
            if (count($this->verdicts) >= self::MAX_ENTRIES) {
                $this->verdicts = [];
            }
        }
        $this->verdicts[$key] = [$now + self::KEEP, $verdict];
        return $verdict;
    }

    /**
     * The ecosystem, name and version of the package a command runs; null — not npx, uvx or pipx, or no package.
     *
     * @return array{string, string, ?string}|null
     */
    public static function package(string $command): ?array {
        preg_match_all('~"([^"]*)"|\'([^\']*)\'|(\S+)~', $command, $m, PREG_SET_ORDER);
        $words = array_map(static fn(array $w): string => $w[3] ?? $w[2] ?? $w[1] ?? '', $m);
        $runner = strtolower((string) preg_replace('~^.*[\\\\/]~', '', $words[0] ?? ''));
        $ecosystem = self::ECOSYSTEMS[$runner] ?? null;
        if ($ecosystem === null) {
            return null;
        }
        $token = null;
        $takeNext = false;
        foreach (array_slice($words, 1) as $word) {
            if ($takeNext) {
                $token = $word;
                break;
            }
            if ($word === '--package' || $word === '-p' || ($runner === 'uvx' && $word === '--from')) {
                $takeNext = true;
                continue;
            }
            if (str_starts_with($word, '--package=') || str_starts_with($word, '--from=')) {
                $token = substr($word, (int) strpos($word, '=') + 1);
                break;
            }
            if (str_starts_with($word, '-')) {
                continue;
            }
            $token = $word;
            break;
        }
        if ($token === null || $token === '') {
            return null;
        }
        [$name, $version] = $ecosystem === 'npm' ? self::npm($token) : self::pypi($token);
        return [$ecosystem, $name, $version];
    }

    /** @return array{string, ?string} @scope/name@version or name@version; latest is no version */
    private static function npm(string $token): array {
        if (preg_match('~^(@[^/]+/[^@]+)(?:@(.+))?$~', $token, $m) === 1 || preg_match('~^([^@]+)(?:@(.+))?$~', $token, $m) === 1) {
            $version = $m[2] ?? null;
            return [$m[1], $version === null || $version === 'latest' ? null : $version];
        }
        return [$token, null];
    }

    /** @return array{string, ?string} name, name[extras], name==version */
    private static function pypi(string $token): array {
        if (preg_match('~^([A-Za-z0-9._-]+)(?:\[[^\]]*\])?(?:==(.+))?$~', $token, $m) === 1) {
            return [$m[1], ($m[2] ?? '') === '' ? null : $m[2]];
        }
        return [$token, null];
    }

    /** @return list<array{id: string, summary: string}> malware advisories only */
    private function query(string $ecosystem, string $name, ?string $version, Cancellation $cancellation): array {
        $payload = ['package' => ['name' => $name, 'ecosystem' => $ecosystem]];
        if ($version !== null) {
            $payload['version'] = $version;
        }
        $request = new Request(self::ENDPOINT, 'POST', json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        $request->setHeader('content-type', 'application/json');
        $request->setBodySizeLimit(self::BODY_LIMIT);
        $response = $this->http->request($request, new CompositeCancellation($cancellation, new TimeoutCancellation(self::TIMEOUT)));
        if ($response->getStatus() !== 200) {
            throw new \RuntimeException('OSV answered ' . $response->getStatus());
        }
        /** @var mixed $body */
        $body = json_decode($response->getBody()->buffer(), true, 64, JSON_THROW_ON_ERROR);
        $found = [];
        /** @var mixed $vulns */
        $vulns = is_array($body) ? ($body['vulns'] ?? []) : [];
        foreach (is_array($vulns) ? $vulns : [] as $vuln) {
            if (!is_array($vuln)) {
                continue;
            }
            /** @var mixed $id */
            $id = $vuln['id'] ?? '';
            /** @var mixed $summary */
            $summary = $vuln['summary'] ?? '';
            if (is_string($id) && str_starts_with($id, 'MAL-')) {
                $found[] = ['id' => $id, 'summary' => is_string($summary) ? trim(preg_replace('~\s+~u', ' ', $summary) ?? '') : ''];
            }
        }
        return $found;
    }
}
