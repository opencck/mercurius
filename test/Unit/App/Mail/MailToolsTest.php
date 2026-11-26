<?php

declare(strict_types=1);

namespace OpenCCK\Test\Unit\App\Mail;

use Amp\NullCancellation;
use Amp\PHPUnit\AsyncTestCase;
use Mercurius\Llm\ToolDefinition;
use OpenCCK\App\Agent\Tool\Guard\AddressGuard;
use OpenCCK\App\Agent\Tool\Tool;
use OpenCCK\App\Agent\Tool\ToolContext;
use OpenCCK\App\Agent\Tool\ToolResult;
use OpenCCK\App\Mail\MailEntries;
use OpenCCK\App\Mail\MailEntryTool;
use OpenCCK\App\Mail\MailTool;
use OpenCCK\Domain\Agent\DangerClass;
use OpenCCK\Domain\Entity\Member;
use OpenCCK\Domain\Entity\MemberKind;
use OpenCCK\Domain\Entity\Role;
use OpenCCK\Domain\Mcp\McpServer;
use OpenCCK\Test\Support\InMemoryMcpServerRepository;

/**
 * spec: brief/skills-mcp.md#ящики-участников-и-проектов — the core around the tools of a mailbox: no host of an
 * internal network, the keeper of the mailbox decides what is sent; the mail tools of the core find the mailbox
 */
final class MailToolsTest extends AsyncTestCase {
    private InMemoryMcpServerRepository $servers;
    private MailEntries $entries;
    private Member $owner;
    private Member $guest;
    /** @var list<array{string, array<string, mixed>}> tool and arguments of each call that reached a mailbox */
    private array $called = [];

    protected function setUp(): void {
        parent::setUp();
        $this->servers = new InMemoryMcpServerRepository();
        $this->entries = new MailEntries($this->servers, static fn(string $install): ?string => $install === 'mail' ? 'https://github.com/opencck/mercurius-mail-mcp' : null);
        $this->owner = new Member(1, MemberKind::Human, Role::admin(), 'Owner');
        $this->guest = new Member(5, MemberKind::Human, Role::user(), 'Guest');
        foreach ([['member:5', 'work', 'mail.example.com'], ['member:5', 'lan', 'lan.local']] as [$context, $name, $host]) {
            $this->servers->add($context, $name, 'stdio:php', null);
            $this->servers->setApproval($context, $name, 'h', 5, ['command' => 'php', 'args' => ['/workspace/mcp/mail/current/bin/mail-mcp-stdio', '--host=' . $host, '--user=g@example.com'], 'env' => []]);
            $this->servers->update($context, $name, McpServer::CONNECTED, 't');
        }
    }

    private function inner(string $name, DangerClass $danger): Tool {
        return new class ($name, $danger, $this) implements Tool {
            public function __construct(private readonly string $name, private readonly DangerClass $danger, private readonly MailToolsTest $test) {}

            public function definition(): ToolDefinition {
                return new ToolDefinition($this->name, '[MCP work] sends a letter', ['type' => 'object', 'properties' => ['to' => ['type' => 'string']]]);
            }

            public function danger(array $args): DangerClass {
                return $this->danger;
            }

            public function untrusted(): bool {
                return true;
            }

            public function timeout(): int {
                return 90;
            }

            public function execute(array $args, ToolContext $context): ToolResult {
                $this->test->reached($this->name, $args);
                return ToolResult::ok('sent');
            }
        };
    }

    /** @param array<string, mixed> $args */
    public function reached(string $tool, array $args): void {
        $this->called[] = [$tool, $args];
    }

    private function entryTool(string $box, string $remote = 'mail_send'): MailEntryTool {
        $target = $this->entries->pick(5, null, $box);
        self::assertInstanceOf(\OpenCCK\App\Mail\MailTarget::class, $target);
        $guard = new AddressGuard(static fn(string $host): array => $host === 'lan.local' ? ['192.168.1.10'] : ['93.184.216.34']);
        return new MailEntryTool($this->inner('mcp_' . $box . '_' . $remote, DangerClass::Irreversible), $remote, $target, $guard, fn(string $context): ?Member => $context === 'member:5' ? $this->guest : null);
    }

    public function testASendWaitsForTheKeeperOfTheMailbox(): void {
        $tool = $this->entryTool('work');

        self::assertSame(DangerClass::Irreversible, $tool->danger([]));
        self::assertSame('mail:mail_send:member:5|work', $tool->grantKey([]));
        self::assertSame($this->guest, $tool->decider([]));
        self::assertFalse($tool->execute(['to' => 'x@example.com'], new ToolContext(1, 1, $this->guest, new NullCancellation()))->isError);
    }

    public function testAHostOfAnInternalNetworkIsRefused(): void {
        $result = $this->entryTool('lan')->execute(['to' => 'x@example.com'], new ToolContext(1, 1, $this->guest, new NullCancellation()));

        self::assertTrue($result->isError);
        self::assertSame([], $this->called);
    }

    public function testTheMailToolOfTheCoreFindsTheMailboxAndCallsItsTool(): void {
        $tools = ['mcp_work_mail_send' => $this->entryTool('work')];
        $router = new MailTool(
            'mail_send',
            $this->inner('mcp_work_mail_send', DangerClass::Irreversible)->definition(),
            $this->entries,
            static fn(int $conversation, Member $who): ?string => null,
            static fn(string $context, string $name) => $tools[$name] ?? null,
        );

        self::assertSame('mcp_mail_mail_send', $router->definition()->name);
        self::assertArrayHasKey('mailbox', (array) ($router->definition()->parameters['properties'] ?? []));
        $prepared = $router->prepareIn(['to' => 'x@example.com', 'mailbox' => 'work'], $this->guest, 7, new NullCancellation());
        self::assertIsArray($prepared);
        self::assertSame('mail:mail_send:member:5|work', $router->grantKey($prepared));
        self::assertSame($this->guest, $router->decider($prepared));
        self::assertSame(DangerClass::Irreversible, $router->danger($prepared));
        self::assertIsString($router->prepareIn(['to' => 'x'], $this->guest, 7, new NullCancellation()), 'two mailboxes and no name: asked which');
        self::assertIsString($router->prepareIn(['to' => 'x', 'mailbox' => 'work'], $this->owner, 7, new NullCancellation()), 'another member\'s mailbox is not reached');

        $result = $router->execute($prepared, new ToolContext(1, 7, $this->guest, new NullCancellation()));

        self::assertSame('sent', $result->content);
        self::assertSame([['mcp_work_mail_send', ['to' => 'x@example.com']]], $this->called, 'the mailbox reaches the server only as its process');
    }
}
