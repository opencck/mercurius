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
        // spec: brief/decisions.md#решения-по-доработкам-2026-11-18 — a tool is checked when it is registered
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
