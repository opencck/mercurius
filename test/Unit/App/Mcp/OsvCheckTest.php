<?php

declare(strict_types=1);

namespace OpenCCK\Test\Unit\App\Mcp;

use Amp\Http\Client\HttpClient;
use Amp\NullCancellation;
use OpenCCK\App\Mcp\OsvCheck;
use OpenCCK\Test\Support\FakeHttpClient;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Before an npx or uvx MCP server is approved its package is looked up in OSV: a known malware advisory stops it,
 * anything else — including a failed lookup — lets it go.
 *
 * spec: brief/skills-mcp.md#скиллы-и-mcp — the check of MCP packages
 */
final class OsvCheckTest extends TestCase {
    private const URL = 'https://api.osv.dev/v1/query';

    private static function check(FakeHttpClient $http, float &$now = 1000.0): OsvCheck {
        return new OsvCheck(new HttpClient($http, []), new NullLogger(), static function () use (&$now): float {
            return $now;
        });
    }

    private static function http(int $status, string $body): FakeHttpClient {
        return new FakeHttpClient([self::URL => [$status, ['content-type' => 'application/json'], $body]]);
    }

    public function testMalwareStopsThePackage(): void {
        $http = self::http(200, '{"vulns":[{"id":"MAL-2026-77","summary":"Malicious code in @evil/mcp"},{"id":"GHSA-xxxx","summary":"a bug"}]}');
        $check = self::check($http);

        $blocked = $check->check('npx -y @evil/mcp@1.2.3 --port 1', new NullCancellation());

        self::assertNotNull($blocked);
        self::assertStringContainsString('MAL-2026-77', $blocked);
        self::assertStringNotContainsString('GHSA', $blocked, 'only malware, not every advisory');
        self::assertSame('{"package":{"name":"@evil/mcp","ecosystem":"npm"},"version":"1.2.3"}', $http->bodies[0] ?? null);
        self::assertSame($blocked, $check->check('npx -y @evil/mcp@1.2.3', new NullCancellation()), 'the verdict is kept');
        self::assertCount(1, $http->requested);
    }

    public function testACleanPackageOrAnotherCommandGoes(): void {
        $http = self::http(200, '{"vulns":[{"id":"CVE-2026-1","summary":"a bug"}]}');
        $check = self::check($http);

        self::assertNull($check->check('uvx mcp-server-fetch==2.0', new NullCancellation()));
        self::assertSame('{"package":{"name":"mcp-server-fetch","ecosystem":"PyPI"},"version":"2.0"}', $http->bodies[0] ?? null);
        self::assertNull($check->check('node server.js', new NullCancellation()));
        self::assertNull($check->check('npx --package=@scope/tool@latest tool-bin', new NullCancellation()));
        self::assertSame('{"package":{"name":"@scope/tool","ecosystem":"npm"}}', $http->bodies[1] ?? null, 'latest is no version');
        self::assertCount(2, $http->requested, 'node is not looked up');
    }

    public function testAFailedLookupLetsThePackageGoAndIsAskedAgain(): void {
        $http = self::http(503, 'busy');
        $check = self::check($http);

        self::assertNull($check->check('npx some-mcp', new NullCancellation()));
        self::assertNull($check->check('npx some-mcp', new NullCancellation()));
        self::assertCount(2, $http->requested, 'a failure is not kept');
    }

    public function testAVerdictIsKeptForAnHour(): void {
        $http = self::http(200, '{}');
        $now = 1000.0;
        $check = self::check($http, $now);

        $check->check('npx some-mcp', new NullCancellation());
        $now += 3000.0;
        $check->check('npx some-mcp', new NullCancellation());
        $now += 700.0;
        $check->check('npx some-mcp', new NullCancellation());

        self::assertCount(2, $http->requested);
    }
}
