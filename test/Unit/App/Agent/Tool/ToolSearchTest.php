<?php

declare(strict_types=1);

namespace OpenCCK\Test\Unit\App\Agent\Tool;

use Amp\NullCancellation;
use Mercurius\Llm\ToolCall;
use Mercurius\Llm\ToolDefinition;
use OpenCCK\App\Agent\Tool\ToolCallValidator;
use OpenCCK\App\Agent\Tool\ToolContext;
use OpenCCK\App\Agent\Tool\ToolRegistry;
use OpenCCK\App\Agent\Tool\ToolSearch;
use OpenCCK\App\Agent\Tool\ToolSearchTool;
use OpenCCK\Domain\Agent\DangerClass;
use OpenCCK\Domain\Agent\ParticipantPolicy;
use OpenCCK\Domain\Agent\RunLimits;
use OpenCCK\Domain\Entity\Principal;
use OpenCCK\Domain\Entity\PrincipalKind;
use OpenCCK\Domain\Entity\Role;
use OpenCCK\Test\Support\FakeTool;
use PHPUnit\Framework\TestCase;

/**
 * Many tools of MCP servers do not go to the model whole: over a threshold it gets their names and three bridge tools.
 *
 * spec: BRIEF.md#скиллы-и-mcp — the search of MCP tools
 */
final class ToolSearchTest extends TestCase {
    /** @return list<ToolDefinition> */
    private static function defs(int $mcp = 3): array {
        $defs = [new ToolDefinition('read_file', 'Reads a file.', ['type' => 'object', 'properties' => ['path' => ['type' => 'string']]])];
        $defs[] = new ToolDefinition('mcp_resources', 'Resources of MCP servers.', ['type' => 'object']);
        $defs[] = new ToolDefinition('mcp_github_create_issue', 'Create an issue in a GitHub repository. Takes a title and a body.', ['type' => 'object', 'properties' => ['title' => ['type' => 'string'], 'body' => ['type' => 'string']]]);
        $defs[] = new ToolDefinition('mcp_mail_send', 'Send an email message to one or more people.', ['type' => 'object', 'properties' => ['to' => ['type' => 'string']]]);
        for ($i = 3; $i <= $mcp; $i++) {
            $defs[] = new ToolDefinition('mcp_big_tool_' . $i, str_repeat('Does a thing with many words. ', 3), ['type' => 'object', 'properties' => ['x' => ['type' => 'string', 'description' => str_repeat('a long text ', 20)]]]);
        }
        return $defs;
    }

    /**
     * @param list<ToolDefinition> $defs
     * @return list<string>
     */
    private static function names(array $defs): array {
        return array_map(static fn(ToolDefinition $d): string => $d->name, $defs);
    }

    public function testFewMcpToolsGoAsTheyAre(): void {
        $search = new ToolSearch(static fn(string $model): int => 200_000);

        self::assertSame(self::names(self::defs()), self::names($search->assemble(self::defs(), 'm')));
    }

    public function testManyMcpToolsAreReplacedByTheBridge(): void {
        $search = new ToolSearch(static fn(string $model): int => 20_000);

        $tools = $search->assemble(self::defs(40), 'm');

        self::assertSame(['read_file', 'mcp_resources', ToolSearch::SEARCH, ToolSearch::DESCRIBE, ToolSearch::CALL], self::names($tools), 'mcp_resources is a core tool');
        self::assertStringContainsString('mcp_github_create_issue', $tools[2]->description, 'the names are listed');
        self::assertStringContainsString('Create an issue in a GitHub repository.', $tools[2]->description, 'with the first sentence');
    }

    public function testATightListingKeepsOnlyTheNames(): void {
        $search = new ToolSearch(static fn(string $model): int => 20_000, listingMaxTokens: 300);

        $description = $search->assemble(self::defs(40), 'm')[2]->description;

        self::assertStringContainsString('mcp_github_create_issue', $description);
        self::assertStringNotContainsString('Create an issue', $description);
    }

    public function testATooBigCatalogIsOnlyCounted(): void {
        $search = new ToolSearch(static fn(string $model): int => 20_000, listingMaxTokens: 20);

        $description = $search->assemble(self::defs(40), 'm')[2]->description;

        self::assertStringNotContainsString('mcp_github_create_issue', $description);
        self::assertStringContainsString('40', $description);
    }

    public function testTheBridgeIsNeverOfferedTwice(): void {
        $search = new ToolSearch(static fn(string $model): int => 20_000);
        $tools = $search->assemble(self::defs(40), 'm');

        self::assertSame(self::names($tools), self::names($search->assemble([...self::defs(40), ...array_slice($tools, 2)], 'm')));
    }

    public function testSearchFindsByTheWordsOfTheQuery(): void {
        $hits = ToolSearch::search(self::defs(40), 'github issue create', 5);

        self::assertSame('mcp_github_create_issue', $hits[0]->name ?? null);
        self::assertNotContains('mcp_mail_send', self::names($hits));
        self::assertSame(['mcp_mail_send'], self::names(ToolSearch::search(self::defs(40), 'mcp_mail_send', 5)), 'an exact name');
        self::assertSame([], ToolSearch::search(self::defs(40), 'calendar', 5));
    }

    private static function registry(): ToolRegistry {
        $registry = new ToolRegistry();
        $registry->register(new FakeTool('read_file'));
        $registry->register(new FakeTool('mcp_github_create_issue', DangerClass::Write, ['type' => 'object', 'properties' => ['title' => ['type' => 'string']], 'required' => ['title']]));
        $registry->register(new FakeTool('mcp_secret_drop'));
        $registry->register(new ToolSearchTool(ToolSearchTool::SEARCH, $registry));
        $registry->register(new ToolSearchTool(ToolSearchTool::DESCRIBE, $registry));
        return $registry;
    }

    private static function policy(): ParticipantPolicy {
        return new ParticipantPolicy(['read_file', 'mcp_github_create_issue'], new RunLimits(30, 1000, 900, 120), null, true);
    }

    public function testToolCallRunsTheToolItNames(): void {
        $validator = new ToolCallValidator(self::registry());

        $call = $validator->validate([new ToolCall('c1', ToolSearch::CALL, '{"name":"mcp_github_create_issue","arguments":{"title":"Bug"}}')], self::policy())->calls[0];

        self::assertNull($call->error);
        self::assertSame('mcp_github_create_issue', $call->call->name);
        self::assertSame(['title' => 'Bug'], $call->args);
        self::assertSame(DangerClass::Write, $call->danger, 'the danger of the tool itself');
        self::assertSame(ToolSearch::CALL, $call->wire?->name, 'the transcript keeps the call as the model made it');
        self::assertSame('c1', $call->call->id);
    }

    public function testToolCallChecksTheToolLikeADirectCall(): void {
        $validator = new ToolCallValidator(self::registry());

        $calls = $validator->validate([
            new ToolCall('c1', ToolSearch::CALL, '{"name":"mcp_secret_drop","arguments":{}}'),
            new ToolCall('c2', ToolSearch::CALL, '{"name":"mcp_github_create_issue","arguments":{}}'),
            new ToolCall('c3', ToolSearch::CALL, '{"name":"tool_call","arguments":{"name":"read_file","arguments":{}}}'),
            new ToolCall('c4', ToolSearch::CALL, '{"arguments":{}}'),
        ], self::policy())->calls;

        self::assertNotNull($calls[0]->error, 'a tool the participant may not use');
        self::assertNotNull($calls[1]->error, 'the schema of the tool');
        self::assertNotNull($calls[2]->error, 'a bridge does not call a bridge');
        self::assertNotNull($calls[3]->error, 'no name');
        self::assertSame(ToolSearch::CALL, $calls[3]->call->name);
    }

    public function testSearchAndDescribeShowOnlyToolsOfTheParticipant(): void {
        $registry = self::registry();
        $context = new ToolContext(1, 1, new Principal(1, PrincipalKind::Human, Role::owner(), null), new NullCancellation(), self::policy());

        $found = $registry->get(ToolSearchTool::SEARCH)?->execute(['queries' => ['github issue', 'secret drop']], $context);
        $described = $registry->get(ToolSearchTool::DESCRIBE)?->execute(['names' => ['mcp_github_create_issue', 'mcp_secret_drop']], $context);

        self::assertNotNull($found);
        self::assertStringContainsString('mcp_github_create_issue', $found->content);
        self::assertStringNotContainsString('mcp_secret_drop"', str_replace('"secret drop"', '', $found->content));
        self::assertNotNull($described);
        self::assertStringContainsString('"required":["title"]', $described->content);
        self::assertStringContainsString('"not_found":["mcp_secret_drop"]', $described->content);
        self::assertTrue($registry->allows(self::policy(), ToolSearchTool::SEARCH), 'the bridge is open to anyone who has MCP tools');
    }
}
