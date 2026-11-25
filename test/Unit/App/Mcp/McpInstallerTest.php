<?php

declare(strict_types=1);

namespace OpenCCK\Test\Unit\App\Mcp;

use Amp\PHPUnit\AsyncTestCase;
use OpenCCK\App\Agent\Sandbox\CommandOutcome;
use OpenCCK\App\Mcp\McpEntry;
use OpenCCK\App\Mcp\McpInstaller;
use OpenCCK\App\Mcp\McpRendered;
use OpenCCK\App\Mcp\McpSources;
use OpenCCK\Domain\Entity\Member;
use OpenCCK\Domain\Entity\MemberKind;
use OpenCCK\Domain\Entity\Role;
use OpenCCK\Domain\Mcp\McpServer;
use OpenCCK\Test\Support\FakeSandbox;
use OpenCCK\Test\Support\InMemoryMcpInstallRepository;

/**
 * spec: brief/skills-mcp.md#установка-и-обновление — sources, a plan the agent made, steps by the install's own user
 * without secrets, a trial start, the approval of the set and its classes, the entry in .mcp.json
 */
final class McpInstallerTest extends AsyncTestCase {
    private FakeSandbox $sandbox;
    private InMemoryMcpInstallRepository $installs;
    private Member $owner;
    /** @var list<array{int, string, array<string, string>}> */
    private array $trials = [];
    /** @var list<array{string, array<string, mixed>}> */
    private array $persisted = [];
    /** @var list<array{name: string, description: string, schema: array<array-key, mixed>, annotations: array<array-key, mixed>}> */
    private array $tools = [['name' => 'exec', 'description' => 'Runs a command', 'schema' => ['type' => 'object'], 'annotations' => []], ['name' => 'list_hosts', 'description' => 'Lists hosts', 'schema' => ['type' => 'object'], 'annotations' => ['readOnlyHint' => true]]];
    /** @var list<string> */
    private array $missing = [];

    protected function setUp(): void {
        parent::setUp();
        $this->sandbox = new FakeSandbox();
        $this->installs = new InMemoryMcpInstallRepository();
        $this->owner = new Member(7, MemberKind::Human, Role::admin(), 'Owner');
    }

    /** @param (\Closure(list<array{string, string, ?string}>): ?string)|null $osv */
    private function installer(?\Closure $osv = null): McpInstaller {
        return new McpInstaller(
            $this->installs,
            new McpSources($this->sandbox),
            $this->sandbox,
            function (int $holder, McpServer $server): array {
                $this->trials[] = [$holder, $server->url, $server->env];
                return $this->tools;
            },
            fn(McpEntry $entry, string $context): McpRendered => $entry->render(fn(string $name): ?string => in_array($name, $this->missing, true) ? null : 'value-of-' . $name),
            function (string $context, McpEntry $entry, Member $by): string {
                $this->persisted[] = [$context, $entry->toArray()];
                return 'approved';
            },
            $osv,
        );
    }

    private function fetched(McpInstaller $installer): void {
        $this->sandbox->outcomes = [
            new CommandOutcome(0, '', '', false, false, 1),
            new CommandOutcome(0, "REV 0123456789ab\n== FILE README.md\nnpm install && npm run build\n", '', false, false, 1),
        ];
        $installer->fetch($this->owner, 'ssh_mcp', 'https://github.com/tufantunc/ssh-mcp', null);
        $this->sandbox->calls = [];
    }

    /** @return array<string, mixed> */
    private static function entry(): array {
        return ['command' => 'node', 'args' => ['${INSTALL_DIR}/build/index.js', '--host={SSH_HOST}'], 'env' => ['SSH_MCP_PASSWORD' => '{SSH_PASSWORD}']];
    }

    public function testFetchingKeepsTheRevisionToInstallAndShowsTheSources(): void {
        $installer = $this->installer();
        $this->sandbox->outcomes = [
            new CommandOutcome(0, '', '', false, false, 1),
            new CommandOutcome(0, "REV 0123456789ab\n== FILE README.md\nnpm install\n", '', false, false, 1),
        ];

        $answer = $installer->fetch($this->owner, 'ssh_mcp', 'https://github.com/tufantunc/ssh-mcp', null);

        self::assertStringContainsString('npm install', $answer);
        $install = $this->installs->find('ssh_mcp');
        self::assertNotNull($install);
        self::assertSame(['0123456789ab', 7], [$install->latest, $install->installedBy]);
    }

    public function testStepsRunByTheInstallsUserWithoutSecretsThenATrialWithThem(): void {
        $installer = $this->installer();
        $this->fetched($installer);

        $answer = $installer->install($this->owner, 'member:7', 'ssh_mcp', ['npm ci', 'npm run build'], self::entry());

        $steps = array_values(array_filter($this->sandbox->calls, static fn(string $c): bool => str_contains($c, 'npm ci')))[0] ?? '';
        self::assertStringStartsWith('exec 800001 ', $steps);
        self::assertStringContainsString("npm ci\nnpm run build", $steps);
        self::assertStringContainsString('@releases/0123456789ab', $steps);
        self::assertSame([], $this->sandbox->env, 'no secret reaches the steps');
        self::assertSame(800001, $this->trials[0][0] ?? null, 'the trial runs as the install too');
        self::assertStringContainsString('/workspace/mcp/ssh_mcp/releases/0123456789ab/build/index.js', $this->trials[0][1]);
        self::assertSame(['SSH_MCP_PASSWORD' => 'value-of-SSH_PASSWORD'], $this->trials[0][2]);
        self::assertStringContainsString('exec', $answer);
        self::assertStringContainsString('list_hosts', $answer);
        self::assertSame([], $this->persisted, 'nothing is written before the approval');
    }

    public function testAMissingSecretStopsBeforeTheTrial(): void {
        $installer = $this->installer();
        $this->fetched($installer);
        $this->missing = ['SSH_PASSWORD'];

        $answer = $installer->install($this->owner, 'member:7', 'ssh_mcp', ['npm ci'], self::entry());

        self::assertStringContainsString('SSH_PASSWORD', $answer);
        self::assertSame([], $this->trials);
    }

    public function testAPackageWithMalwareStopsBeforeTheSteps(): void {
        $installer = $this->installer(static fn(array $packages): string => 'MAL-1 in evil');
        $this->fetched($installer);
        $this->sandbox->files[800001]['releases/0123456789ab/package.json'] = '{"dependencies": {"evil": "^1.0.0"}}';

        $answer = $installer->install($this->owner, 'member:7', 'ssh_mcp', ['npm ci'], self::entry());

        self::assertStringContainsString('MAL-1', $answer);
        self::assertSame([], array_values(array_filter($this->sandbox->calls, static fn(string $c): bool => str_contains($c, 'npm ci'))));
    }

    public function testTheApprovalSetsTheClassesSwitchesTheRevisionAndWritesTheEntry(): void {
        $installer = $this->installer();
        $this->fetched($installer);
        $installer->install($this->owner, 'member:7', 'ssh_mcp', ['npm ci'], self::entry());

        $answer = $installer->approve($this->owner, 'ssh_mcp', 'hints');

        $install = $this->installs->find('ssh_mcp') ?? self::fail('installed');
        self::assertSame('0123456789ab', $install->revision);
        self::assertSame(['exec' => 'irreversible', 'list_hosts' => 'read'], $install->classes);
        self::assertStringContainsString("ln -sfn 'releases/0123456789ab' current", implode("\n", $this->sandbox->calls));
        $written = reset($this->persisted);
        self::assertNotFalse($written);
        self::assertSame('member:7', $written[0]);
        self::assertSame(['type' => 'stdio', 'command' => 'node', 'args' => ['/workspace/mcp/ssh_mcp/current/build/index.js', '--host={SSH_HOST}'], 'env' => ['SSH_MCP_PASSWORD' => '{SSH_PASSWORD}']], $written[1]);
        self::assertStringContainsString('approved', $answer);
    }

    public function testWithoutTrustInHintsEveryToolIsIrreversible(): void {
        $installer = $this->installer();
        $this->fetched($installer);
        $installer->install($this->owner, 'member:7', 'ssh_mcp', ['npm ci'], self::entry());

        $installer->approve($this->owner, 'ssh_mcp', 'irreversible');

        self::assertSame(['exec' => 'irreversible', 'list_hosts' => 'irreversible'], $this->installs->find('ssh_mcp')?->classes);
    }

    public function testAnotherMemberCannotApproveOrReinstallSomeoneElsesMcp(): void {
        $installer = $this->installer();
        $this->fetched($installer);
        $installer->install($this->owner, 'member:7', 'ssh_mcp', ['npm ci'], self::entry());
        $other = new Member(9, MemberKind::Human, Role::define('ops', '', 15, [\OpenCCK\Domain\Entity\Permission::Mcp]), 'Ops');

        self::assertNotSame('approved', $installer->approve($other, 'ssh_mcp', 'hints'));
        self::assertNull($this->installs->find('ssh_mcp')?->revision);
        self::assertStringNotContainsString('REV', $installer->fetch($other, 'ssh_mcp', 'https://github.com/evil/x', null));
    }
}
