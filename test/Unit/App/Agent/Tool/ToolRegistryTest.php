<?php

declare(strict_types=1);

namespace OpenCCK\Test\Unit\App\Agent\Tool;

use LogicException;
use OpenCCK\App\Agent\Tool\ToolRegistry;
use OpenCCK\Domain\Agent\ParticipantPolicy;
use OpenCCK\Domain\Agent\RunLimits;
use OpenCCK\Test\Support\FakeTool;
use PHPUnit\Framework\TestCase;

final class ToolRegistryTest extends TestCase {
    /** @param list<string>|null $tools */
    private function policy(?array $tools): ParticipantPolicy {
        return new ParticipantPolicy($tools, new RunLimits(30, 1000, 900, 120), null, true);
    }

    public function testDefinitionsFollowPolicyInRegistrationOrder(): void {
        $registry = new ToolRegistry();
        $registry->register(new FakeTool('b'));
        $registry->register(new FakeTool('a'));

        self::assertSame(['b', 'a'], $registry->names($this->policy(null)));
        self::assertSame(['a'], array_map(static fn($d) => $d->name, $registry->definitions($this->policy(['a']))));
        self::assertSame([], $registry->names($this->policy([])));
    }

    public function testAToolOfAModuleServiceOnlyWhileTheServiceIsUp(): void {
        $registry = new ToolRegistry();
        $registry->register(new FakeTool('a'));
        $registry->register(new ServiceToolStub());
        /** @var \ArrayObject<string, bool> $state */
        $state = new \ArrayObject(['up' => false]);
        $registry->withServices(static fn(string $name): bool => $name === 'media-docs' && ($state['up'] ?? false));

        self::assertSame(['a'], $registry->names($this->policy(null)));
        self::assertFalse($registry->allows($this->policy(null), 'docs'));
        $state['up'] = true;
        self::assertSame(['a', 'docs'], $registry->names($this->policy(null)));
    }

    public function testDuplicateNameIsRejected(): void {
        $registry = new ToolRegistry();
        $registry->register(new FakeTool('a'));

        $this->expectException(LogicException::class);
        $registry->register(new FakeTool('a'));
    }

    public function testAToolWithABadSchemaIsNotRegistered(): void {
        $registry = new ToolRegistry();
        // spec: brief/agent-loop.md#инструменты — a tool is checked when it is registered
        foreach ([
            new FakeTool('bad name!'),
            new FakeTool('list', parameters: ['type' => 'array']),
            new FakeTool('props', parameters: ['type' => 'object', 'properties' => 'x']),
            new FakeTool('req', parameters: ['type' => 'object', 'properties' => ['a' => ['type' => 'string']], 'required' => ['b']]),
        ] as $tool) {
            try {
                $registry->register($tool);
                self::fail('registered: ' . $tool->definition()->name);
            } catch (\InvalidArgumentException $e) {
                self::assertNotSame('', $e->getMessage());
            }
        }
        $registry->register(new FakeTool('typeless', parameters: ['properties' => ['a' => ['type' => 'string']]]));
        self::assertNotNull($registry->get('typeless'), 'a missing root type is taken as an object, as hermes repairs it');
    }

    public function testGet(): void {
        $registry = new ToolRegistry();
        $tool = new FakeTool('a');
        $registry->register($tool);

        self::assertSame($tool, $registry->get('a'));
        self::assertNull($registry->get('b'));
    }

    /** spec: brief/skills-mcp.md#процессы-и-реестр-инструментов-по-контекстам */
    public function testToolsOfAContextOnlyThere(): void {
        $registry = new ToolRegistry();
        $registry->register(new FakeTool('read_file'));
        self::assertTrue($registry->registerIn('project:1', new FakeTool('mcp_docs_search')));

        $inProject = $this->policy(null)->withMcpContext('project:1');
        self::assertSame(['read_file', 'mcp_docs_search'], $registry->names($inProject));
        self::assertNotNull($registry->get('mcp_docs_search', $inProject));
        self::assertSame(['read_file'], $registry->names($this->policy(null)->withMcpContext('member:7')));
        self::assertSame(['read_file'], $registry->names($this->policy(null)), 'a run without a context gets no connection tools');
        self::assertNull($registry->get('mcp_docs_search'));
        self::assertFalse($registry->allows($this->policy(null), 'mcp_docs_search'));

        $registry->removeIn('project:1', 'mcp_docs_search');
        self::assertSame(['read_file'], $registry->names($inProject));
    }

    public function testTwoProjectsSameName(): void {
        $registry = new ToolRegistry();
        $one = new FakeTool('mcp_docs_search');
        $two = new FakeTool('mcp_docs_search');
        self::assertTrue($registry->registerIn('project:1', $one));
        self::assertTrue($registry->registerIn('project:2', $two));

        self::assertSame($one, $registry->get('mcp_docs_search', $this->policy(null)->withMcpContext('project:1')));
        self::assertSame($two, $registry->get('mcp_docs_search', $this->policy(null)->withMcpContext('project:2')));
        self::assertFalse($registry->registerIn('project:1', new FakeTool('mcp_docs_search')), 'a name once per context');
    }

    public function testNameTakenByCoreIsRefused(): void {
        $registry = new ToolRegistry();
        $registry->register(new FakeTool('mcp_mail_mail_list'));

        self::assertFalse($registry->registerIn('member:7', new FakeTool('mcp_mail_mail_list')));
        self::assertSame(['mcp_mail_mail_list'], $registry->names($this->policy(null)->withMcpContext('member:7')));
    }

    public function testTheContextSurvivesNarrowing(): void {
        $policy = $this->policy(null)->withMcpContext('project:3', open: true);

        self::assertSame('project:3', $policy->capTo($this->policy(['a']))->mcpContext);
        self::assertSame('project:3', $policy->narrowedBy($this->policy(['a']), keepApproval: true)->mcpContext);
        self::assertSame('project:3', $policy->without(['a'])->mcpContext);
        self::assertSame('project:3', $policy->readOnlyCopy()->mcpContext);
        self::assertTrue($policy->without(['a'])->mcpOpen);
        self::assertFalse($policy->capTo($this->policy(['a']))->mcpOpen, 'a narrowing closes the way around the list');
        self::assertFalse($policy->narrowedBy($this->policy(['a']), keepApproval: true)->mcpOpen);
    }

    /** spec: brief/orchestration.md#права-воркера-задаёт-оркестратор — a list stays a list for connection tools too */
    public function testANarrowedListKeepsConnectionToolsOut(): void {
        $registry = new ToolRegistry();
        $registry->registerIn('project:1', new FakeTool('mcp_docs_search'));
        $registry->registerIn('project:1', new FakeTool('mcp_docs_write'));

        $listed = $this->policy(['mcp_docs_search'])->withMcpContext('project:1');
        self::assertSame(['mcp_docs_search'], $registry->names($listed), 'a worker or the self-review gets what its list names');
        $open = $this->policy([])->withMcpContext('project:1', open: true);
        self::assertSame(['mcp_docs_search', 'mcp_docs_write'], $registry->names($open), 'the place itself: every connection of its context');
        self::assertSame([], $registry->names($open->narrowedBy($this->policy([]))), 'a stranger in the group of a project narrows them away');
    }

    public function testACommonToolRegisteredLaterIsNotOfferedTwice(): void {
        $registry = new ToolRegistry();
        self::assertTrue($registry->registerIn('member:7', new FakeTool('mcp_mail_mail_list')));
        $registry->register(new FakeTool('mcp_mail_mail_list'));

        $policy = $this->policy(null)->withMcpContext('member:7', open: true);
        self::assertSame(['mcp_mail_mail_list'], $registry->names($policy));
        self::assertCount(1, $registry->definitions($policy));
    }
}

/** A tool of the media-docs module. */
final class ServiceToolStub implements \OpenCCK\App\Agent\Tool\Tool, \OpenCCK\App\Agent\Tool\NeedsService {
    public function service(): string {
        return 'media-docs';
    }

    public function definition(): \Mercurius\Llm\ToolDefinition {
        return new \Mercurius\Llm\ToolDefinition('docs', 'docs', ['type' => 'object', 'properties' => []]);
    }

    public function danger(array $args): \OpenCCK\Domain\Agent\DangerClass {
        return \OpenCCK\Domain\Agent\DangerClass::Read;
    }

    public function untrusted(): bool {
        return false;
    }

    public function timeout(): int {
        return 30;
    }

    public function execute(array $args, \OpenCCK\App\Agent\Tool\ToolContext $context): \OpenCCK\App\Agent\Tool\ToolResult {
        return \OpenCCK\App\Agent\Tool\ToolResult::ok('docs');
    }
}
