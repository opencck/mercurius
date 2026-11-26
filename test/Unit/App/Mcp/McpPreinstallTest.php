<?php

declare(strict_types=1);

namespace OpenCCK\Test\Unit\App\Mcp;

use Amp\PHPUnit\AsyncTestCase;
use OpenCCK\App\Agent\Sandbox\CommandOutcome;
use OpenCCK\App\Mcp\McpBundle;
use OpenCCK\App\Mcp\McpEntry;
use OpenCCK\App\Mcp\McpInstaller;
use OpenCCK\App\Mcp\McpPreinstall;
use OpenCCK\App\Mcp\McpRendered;
use OpenCCK\App\Mcp\McpSources;
use OpenCCK\Domain\Entity\Member;
use OpenCCK\Domain\Entity\MemberKind;
use OpenCCK\Domain\Entity\Role;
use OpenCCK\Domain\Mcp\McpServer;
use OpenCCK\Test\Support\FakeSandbox;
use OpenCCK\Test\Support\InMemoryMcpInstallRepository;
use Psr\Log\NullLogger;

/**
 * spec: brief/skills-mcp.md#предустановленные-mcp-ssh-и-mail — the MCPs of the delivery are installed at start from the
 * sandbox image, as an install of the owner of the agent, with no card: the code is ours; they update with the agent
 */
final class McpPreinstallTest extends AsyncTestCase {
    private FakeSandbox $sandbox;
    private InMemoryMcpInstallRepository $installs;
    private Member $owner;
    /** @var list<array{int, string}> */
    private array $trials = [];
    /** @var list<array{string, array<string, mixed>}> */
    private array $persisted = [];

    protected function setUp(): void {
        parent::setUp();
        $this->sandbox = new FakeSandbox();
        $this->installs = new InMemoryMcpInstallRepository();
        $this->owner = new Member(1, MemberKind::Human, Role::admin(), 'Owner');
    }

    private function installer(): McpInstaller {
        $installer = new McpInstaller(
            $this->installs,
            new McpSources($this->sandbox),
            $this->sandbox,
            function (int $holder, McpServer $server): array {
                $this->trials[] = [$holder, $server->url];
                return [
                    ['name' => 'run', 'description' => 'Runs a command', 'schema' => ['type' => 'object'], 'annotations' => []],
                    ['name' => 'read_file', 'description' => 'Reads a file', 'schema' => ['type' => 'object'], 'annotations' => ['readOnlyHint' => true]],
                ];
            },
            fn(McpEntry $entry, string $context): McpRendered => $entry->render(static fn(string $name): ?string => null),
            function (string $context, McpEntry $entry, Member $by): string {
                $this->persisted[] = [$context, ['name' => $entry->name] + $entry->toArray()];
                return 'approved';
            },
        );
        $installer->useBundled(['ssh', 'mail']);
        return $installer;
    }

    private static function bundle(string $revision = '0a1b2c3d4e5f'): McpBundle {
        return new McpBundle('ssh', 'https://github.com/opencck/mercurius-mcp-ssh', $revision, ['command' => 'php', 'args' => ['${INSTALL_DIR}/bin/ssh-mcp']]);
    }

    public function testTheIndexOfTheImageNamesTheBundles(): void {
        $index = json_encode([
            ['name' => 'ssh', 'package' => 'opencck/mercurius-mcp-ssh', 'revision' => '0a1b2c3d4e5f', 'plan' => ['steps' => ['composer install'], 'entry' => ['command' => 'php', 'args' => ['${INSTALL_DIR}/bin/ssh-mcp']]]],
            ['name' => 'Bad Name', 'package' => 'x/y', 'revision' => '0a1b2c3d4e5f', 'plan' => ['entry' => ['command' => 'php']]],
            ['name' => 'nope', 'package' => 'x/y', 'revision' => '../up', 'plan' => ['entry' => ['command' => 'php']]],
        ], JSON_THROW_ON_ERROR);

        $bundles = McpBundle::fromIndex($index);

        self::assertCount(1, $bundles, 'a bad name or revision is skipped');
        self::assertSame(['ssh', 'https://github.com/opencck/mercurius-mcp-ssh', '0a1b2c3d4e5f'], [$bundles[0]->name, $bundles[0]->source, $bundles[0]->revision]);
        self::assertSame([], McpBundle::fromIndex('not json'));
    }

    public function testABundleIsCopiedTriedAndPinnedWithNoCard(): void {
        $result = $this->installer()->preinstall($this->owner, self::bundle(), null);

        $install = $this->installs->find('ssh');
        self::assertNotNull($install);
        self::assertSame(['https://github.com/opencck/mercurius-mcp-ssh', 1, '0a1b2c3d4e5f'], [$install->source, $install->installedBy, $install->revision]);
        self::assertSame(['run' => 'irreversible', 'read_file' => 'read'], $install->classes, 'the classes are the marks of our own code');
        self::assertSame(['type' => 'stdio', 'command' => 'php', 'args' => ['/workspace/mcp/ssh/current/bin/ssh-mcp']], $install->entry);
        $copy = array_values(array_filter($this->sandbox->calls, static fn(string $c): bool => str_contains($c, 'cp -R')))[0] ?? '';
        self::assertStringStartsWith('exec 800001 ', $copy, 'copied by the install\'s own user');
        self::assertStringContainsString("cp -R '/opt/mercurius/mcp/ssh' 'releases/0a1b2c3d4e5f'", $copy);
        self::assertStringContainsString('/workspace/mcp/ssh/releases/0a1b2c3d4e5f/bin/ssh-mcp', $this->trials[0][1] ?? '');
        self::assertNotEmpty(array_filter($this->sandbox->calls, static fn(string $c): bool => str_contains($c, "ln -sfn 'releases/0a1b2c3d4e5f' current")));
        self::assertSame([], $this->persisted, 'no entry without a sample');
        self::assertStringContainsString('0a1b2c3d4e5f', $result);
    }

    public function testTheSameRevisionIsLeftAloneAndANewOneReplacesAnInstallFromGit(): void {
        $installer = $this->installer();
        $installer->preinstall($this->owner, self::bundle(), null);
        $this->sandbox->calls = [];

        $installer->preinstall($this->owner, self::bundle(), null);
        self::assertSame([], $this->sandbox->calls, 'nothing runs when the revision is in use');

        $this->installs->setLatest('ssh', 'e181826aaaaa');
        $installer->preinstall($this->owner, self::bundle('ffff00001111'), null);
        $install = $this->installs->find('ssh');
        self::assertSame(['ffff00001111', '0a1b2c3d4e5f', null], [$install?->revision, $install?->previous, $install?->latest]);
    }

    public function testAnInstallOfTheSameNameFromAnotherSourceIsNotTouched(): void {
        $this->installs->add('ssh', 'https://github.com/tufantunc/ssh-mcp', 5);

        $result = $this->installer()->preinstall($this->owner, self::bundle(), null);

        self::assertNull($this->installs->find('ssh')?->revision);
        self::assertSame([], $this->sandbox->calls);
        self::assertStringContainsString('tufantunc', $result);
    }

    public function testASampleEntryGoesIntoTheOwnersFile(): void {
        $this->installer()->preinstall($this->owner, self::bundle(), ['name' => 'ssh', 'args' => ['--host=', '--user=root'], 'env' => ['SSH_PASSWORD' => '{SSH_PASSWORD}']]);

        self::assertSame([['member:1', [
            'name' => 'ssh',
            'type' => 'stdio',
            'command' => 'php',
            'args' => ['/workspace/mcp/ssh/current/bin/ssh-mcp', '--host=', '--user=root'],
            'env' => ['SSH_PASSWORD' => '{SSH_PASSWORD}'],
        ]]], $this->persisted);
    }

    public function testDependenciesAreBuiltAgainWhenTheEnvironmentsOfTheImageChange(): void {
        // spec: brief/skills-mcp.md#установка-и-обновление — a change of the environments in the image breaks dependencies
        $installer = $this->installer();
        $this->installs->add('weather', 'https://github.com/x/weather', 7);
        $this->installs->setRevision('weather', '0123456789ab', 'h', ['forecast' => 'read'], ['command' => 'node', 'args' => ['/workspace/mcp/weather/current/index.js']]);
        $this->installs->setSteps('weather', ['npm ci']);

        self::assertSame([], $installer->rebuildChanged('node v20|py 3.11'), 'the first look only remembers the environments');
        self::assertSame([], $installer->rebuildChanged('node v20|py 3.11'));
        self::assertSame([], array_filter($this->sandbox->calls, static fn(string $c): bool => str_contains($c, 'npm ci')));

        $rebuilt = $installer->rebuildChanged('node v22|py 3.11');

        self::assertSame([[7, 'weather', null]], $rebuilt, 'the installer is told; null — no failure');
        $steps = array_values(array_filter($this->sandbox->calls, static fn(string $c): bool => str_contains($c, 'npm ci')))[0] ?? '';
        self::assertStringStartsWith('exec 800001 ', $steps);
        self::assertStringContainsString('@releases/0123456789ab', $steps);
        self::assertSame([], $installer->rebuildChanged('node v22|py 3.11'), 'built once for the new environments');
    }

    public function testABuildOfTheRevisionInUseIsAskedForByName(): void {
        $installer = $this->installer();
        $this->installs->add('weather', 'https://github.com/x/weather', 1);
        $this->installs->setRevision('weather', '0123456789ab', 'h', [], ['command' => 'node']);
        $this->installs->setSteps('weather', ['npm ci']);

        self::assertStringContainsString('weather', $installer->rebuild($this->owner, 'weather'));
        self::assertNotEmpty(array_filter($this->sandbox->calls, static fn(string $c): bool => str_contains($c, 'npm ci')));
        self::assertStringContainsString('ssh', $installer->rebuild($this->owner, 'ssh'), 'nothing to build: not installed');
    }

    public function testABundleUpdatesWithTheAgentOnly(): void {
        $installer = $this->installer();
        $installer->preinstall($this->owner, self::bundle(), null);
        $this->sandbox->outcomes = [new CommandOutcome(0, "ffff00001111aaaa\tHEAD\n", '', false, false, 1)];

        self::assertSame([], $installer->checkUpdates(), 'its source is not watched');
        self::assertStringContainsString('ssh', $installer->update($this->owner, 'ssh'));
        self::assertSame('0a1b2c3d4e5f', $this->installs->find('ssh')?->revision);
        $installer->remove($this->owner, 'ssh');
        self::assertNotNull($this->installs->find('ssh'), 'nor is it removed: it would come back at the next start');
    }

    private function index(): string {
        return json_encode([
            ['name' => 'ssh', 'package' => 'opencck/mercurius-mcp-ssh', 'revision' => '0a1b2c3d4e5f', 'plan' => ['entry' => ['command' => 'php', 'args' => ['${INSTALL_DIR}/bin/ssh-mcp']]]],
            ['name' => 'mail', 'package' => 'opencck/mercurius-mail-mcp', 'revision' => '1a1b2c3d4e5f', 'plan' => ['entry' => ['command' => 'php', 'args' => ['${INSTALL_DIR}/bin/mail-mcp-stdio']]]],
        ], JSON_THROW_ON_ERROR);
    }

    private function preinstall(bool $ownerHasEntries): McpPreinstall {
        $this->sandbox->outcomes = [new CommandOutcome(0, $this->index(), '', false, false, 1)];
        return new McpPreinstall($this->installer(), $this->installs, $this->sandbox, fn(): Member => $this->owner, static fn(string $context): bool => $ownerHasEntries, new NullLogger());
    }

    public function testACleanInstallGetsSampleEntriesThatWaitForTheirSecrets(): void {
        $this->preinstall(false)->run();

        self::assertSame(['ssh', 'mailbox'], array_map(static fn(array $p): mixed => $p[1]['name'], $this->persisted));
        self::assertSame(['MAIL_PASSWORD' => '{MAIL_PASSWORD}'], $this->persisted[1][1]['env'] ?? null);
        self::assertNotNull($this->installs->find('mail')?->revision);
    }

    public function testAnInstanceInUseGetsNoSamples(): void {
        $this->preinstall(true)->run();
        self::assertSame([], $this->persisted, 'the owner keeps entries');

        $this->installs = new InMemoryMcpInstallRepository();
        $this->installs->add('browser_tools', 'https://github.com/x/y', 1);
        $this->preinstall(false)->run();
        self::assertSame([], $this->persisted, 'something was installed before');
    }
}
