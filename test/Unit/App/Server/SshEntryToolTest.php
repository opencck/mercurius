<?php

declare(strict_types=1);

namespace OpenCCK\Test\Unit\App\Server;

use Amp\NullCancellation;
use Amp\PHPUnit\AsyncTestCase;
use Mercurius\Llm\ToolDefinition;
use OpenCCK\App\Agent\Tool\Tool;
use OpenCCK\App\Agent\Tool\ToolContext;
use OpenCCK\App\Agent\Tool\ToolResult;
use OpenCCK\App\Server\SshEntryTool;
use OpenCCK\App\Server\SshTarget;
use OpenCCK\CommandPolicy\SshCommandClassifier;
use OpenCCK\Domain\Agent\DangerClass;
use OpenCCK\Domain\Agent\ParticipantPolicy;
use OpenCCK\Domain\Agent\RunLimits;
use OpenCCK\Domain\Entity\Member;
use OpenCCK\Domain\Entity\MemberKind;
use OpenCCK\Domain\Entity\Role;
use OpenCCK\Test\Support\SshFixture;

/**
 * spec: brief/servers.md#ssh-как-предустановленный-mcp — the tools of our MCP go through the wrapper of the core: the
 * policy decides the card, the journal keeps the call, the process of the entry runs it
 */
final class SshEntryToolTest extends AsyncTestCase {
    private SshFixture $ssh;
    private SshTarget $target;
    private Member $owner;

    protected function setUp(): void {
        parent::setUp();
        $this->ssh = new SshFixture();
        $server = $this->ssh->entry('project:2', 'web', ['--host=10.0.0.5', '--user=deploy']);
        $this->target = $this->ssh->entries->target($server) ?? self::fail();
        $this->owner = new Member(1, MemberKind::Human, Role::admin(), null);
    }

    /** @param list<string> $confirmGroups */
    private function tool(string $remote, array $confirmGroups = []): SshEntryTool {
        $inner = new class ($remote) implements Tool {
            public function __construct(private readonly string $remote) {}

            public function definition(): ToolDefinition {
                return new ToolDefinition('mcp_web_' . $this->remote, '[MCP web] ' . $this->remote, ['type' => 'object', 'properties' => new \stdClass()]);
            }

            public function danger(array $args): DangerClass {
                return DangerClass::Read;
            }

            public function untrusted(): bool {
                return true;
            }

            public function timeout(): int {
                return 90;
            }

            public function execute(array $args, ToolContext $context): ToolResult {
                throw new \LogicException('the wrapper never lets the call go around it');
            }
        };
        return new SshEntryTool($inner, $remote, $this->target, $this->ssh->console($confirmGroups), new SshCommandClassifier());
    }

    private function context(bool $approved = false): ToolContext {
        $policy = (new ParticipantPolicy(null, new RunLimits(30, 1000, 900, 120), null, true))->withMcpContext('project:2', open: true);
        return new ToolContext(5, 1, $this->owner, new NullCancellation(), approved: $approved, policy: $policy);
    }

    public function testACommandIsClassifiedJournalledAndRunByTheProcess(): void {
        $tool = $this->tool('run');

        self::assertSame('mcp_web_run', $tool->definition()->name);
        self::assertSame(DangerClass::Read, $tool->danger(['command' => 'df -h']));
        self::assertSame(DangerClass::Irreversible, $tool->danger(['command' => 'systemctl restart nginx']));
        self::assertNull($tool->grantKey(['command' => 'df -h']));
        self::assertStringStartsWith('ssh:v' . SshCommandClassifier::VERSION . ':project:2|web:', (string) $tool->grantKey(['command' => 'systemctl restart nginx']));
        self::assertSame('ssh-any:project:2|web', $tool->wideGrant(['command' => 'systemctl restart nginx'])[0] ?? null);

        $result = $tool->execute(['command' => 'df -h', 'timeout' => 20], $this->context());

        self::assertFalse($result->isError, $result->content);
        self::assertSame([['project:2|web', ['command' => 'df -h', 'timeout' => 20]]], $this->ssh->calls);
        self::assertSame(['start', 'done'], array_column($this->ssh->audit->records, 'event'));
    }

    public function testNeverIsRefusedWithoutTheOwner(): void {
        $result = $this->tool('run')->execute(['command' => 'rm -rf /'], $this->context());

        self::assertTrue($result->isError);
        self::assertSame([], $this->ssh->calls);
        self::assertStringStartsWith('ssh-never:', (string) $this->tool('run')->grantKey(['command' => 'rm -rf /']));
    }

    public function testAFileIsReadAndWrittenThroughTheCommandOfTheProcess(): void {
        self::assertSame(DangerClass::Read, $this->tool('read_file')->danger(['path' => '/etc/hosts']));
        self::assertSame(DangerClass::Irreversible, $this->tool('write_file')->danger(['path' => '/tmp/x', 'content' => 'a']));

        $this->tool('read_file')->execute(['path' => '/etc/hosts'], $this->context());
        $this->tool('write_file')->execute(['path' => "/tmp/it's", 'content' => 'data'], $this->context(true));

        self::assertSame(["head -c 1048576 -- '/etc/hosts'", "cat > '/tmp/it'\\''s'"], $this->ssh->commands());
        self::assertSame('data', $this->ssh->calls[1][1]['stdin'] ?? null);
        self::assertSame(['read', 'change'], [$this->ssh->audit->records[0]['class'], $this->ssh->audit->records[2]['class']]);
    }

    public function testAForbiddenPlaceIsNotWrittenWithoutTheOwner(): void {
        $tool = $this->tool('write_file');

        $result = $tool->execute(['path' => '/root/.ssh/authorized_keys', 'content' => 'k'], $this->context());

        self::assertTrue($result->isError);
        self::assertSame([], $this->ssh->calls);
        self::assertStringStartsWith('file-write-never:project:2|web:', (string) $tool->grantKey(['path' => '/root/.ssh/authorized_keys', 'content' => 'k']));
    }

    public function testAProbeTakesOnlyAHostAndAPort(): void {
        $tool = $this->tool('tcp_probe');

        $tool->execute(['host' => 'db.local', 'port' => 5432], $this->context());
        $bad = $tool->execute(['host' => 'x; rm -rf /', 'port' => 22], $this->context());

        self::assertTrue($bad->isError);
        self::assertCount(1, $this->ssh->calls);
        self::assertStringContainsString('/dev/tcp/db.local/5432', $this->ssh->commands()[0]);
    }

    public function testAConfirmedGroupAsksEvenForAReadOfAFile(): void {
        $this->ssh->entry('project:2', 'prod', ['--host=10.0.0.9', '--user=deploy', '--group=prod']);
        $this->target = $this->ssh->entries->find('prod', 1, 'project:2') ?? self::fail();

        self::assertSame(DangerClass::Irreversible, $this->tool('read_file', ['prod'])->danger(['path' => '/etc/hosts']));
        self::assertSame(DangerClass::Irreversible, $this->tool('run', ['prod'])->danger(['command' => 'uptime']));
    }
}
