<?php

declare(strict_types=1);

namespace OpenCCK\Test\Unit\App\Mcp;

use Amp\PHPUnit\AsyncTestCase;
use OpenCCK\App\Agent\Sandbox\CommandOutcome;
use OpenCCK\App\Mcp\McpSources;
use OpenCCK\Domain\Mcp\McpInstall;
use OpenCCK\Test\Support\FakeSandbox;

/**
 * spec: brief/skills-mcp.md#установка-и-обновление — the sources come into the home of the installed MCP's own user,
 * as a revision next to the others; the answer is what the agent reads to make a plan
 */
final class McpSourcesTest extends AsyncTestCase {
    private const SHA = '0123456789abcdef0123456789abcdef01234567';
    private FakeSandbox $sandbox;

    protected function setUp(): void {
        parent::setUp();
        $this->sandbox = new FakeSandbox();
    }

    private function install(): McpInstall {
        return new McpInstall(3, 'ssh-mcp', 'https://github.com/tufantunc/ssh-mcp', 7);
    }

    public function testAGitSourceIsClonedByTheInstallsOwnUserAndPinned(): void {
        $this->sandbox->outcomes = [
            new CommandOutcome(0, '', '', false, false, 1),
            new CommandOutcome(0, 'REV ' . self::SHA . "\n== FILE README.md\nssh-mcp server\n== FILES\n./README.md\n./package.json\n", '', false, false, 1),
        ];

        $fetched = (new McpSources($this->sandbox))->fromGit($this->install(), 'https://github.com/tufantunc/ssh-mcp');

        self::assertSame('0123456789ab', $fetched->revision);
        self::assertStringContainsString('ssh-mcp server', $fetched->overview);
        self::assertStringStartsWith('exec 0 ', $this->sandbox->calls[0] ?? '', 'the owners link the code at /workspace/mcp/<name>');
        self::assertStringContainsString("ln -sfn '../p800003' 'mcp/ssh-mcp'", $this->sandbox->calls[0] ?? '');
        self::assertStringStartsWith('exec 800003 ', $this->sandbox->calls[1] ?? '');
        self::assertStringContainsString("git clone --quiet --depth 1 -- 'https://github.com/tufantunc/ssh-mcp' releases/.incoming", $this->sandbox->calls[1] ?? '');
        self::assertStringContainsString('mv releases/.incoming "releases/$short"', $this->sandbox->calls[1] ?? '');
    }

    public function testAnAddressThatIsNotAPlainHttpsRepositoryIsRefused(): void {
        foreach (['http://github.com/x/y', 'https://github.com/x/y?z=1', "https://github.com/x/y'; rm -rf /", 'git@github.com:x/y.git', 'https://user@github.com/x/y', 'file:///etc'] as $url) {
            try {
                (new McpSources($this->sandbox))->fromGit($this->install(), $url);
                self::fail($url);
            } catch (\DomainException) {
                // refused
            }
        }
        self::assertSame([], $this->sandbox->calls);
    }

    public function testAnArchiveIsCheckedBeforeItIsUnpacked(): void {
        $this->sandbox->outcomes = [
            new CommandOutcome(0, '', '', false, false, 1),
            new CommandOutcome(0, "REV x\n== FILES\n./index.js\n", '', false, false, 1),
        ];

        $fetched = (new McpSources($this->sandbox))->fromArchive($this->install(), 'server.tar.gz', 'archive-bytes');

        self::assertSame('a' . substr(hash('sha256', 'archive-bytes'), 0, 11), $fetched->revision);
        self::assertSame('archive-bytes', $this->sandbox->files[800003]['incoming.archive'] ?? null);
        $unpack = $this->sandbox->calls[2] ?? '';
        self::assertStringContainsString('tar -tzf incoming.archive', $unpack, 'the names are read first');
        self::assertStringContainsString('--no-same-owner', $unpack);
        self::assertStringContainsString('find releases/.incoming -type l', $unpack, 'links leading out are refused');
    }

    public function testAnArchiveOfAnotherKindIsRefused(): void {
        $this->expectException(\DomainException::class);

        (new McpSources($this->sandbox))->fromArchive($this->install(), 'server.rar', 'x');
    }
}
