<?php

declare(strict_types=1);

namespace OpenCCK\Test\Unit\App\Mcp;

use Amp\Cancellation;
use Amp\NullCancellation;
use Amp\PHPUnit\AsyncTestCase;
use Mercurius\Mcp\McpClient;
use Mercurius\Mcp\McpEndpoint;
use OpenCCK\App\Agent\Tool\Tool;
use OpenCCK\App\Agent\Tool\ToolRegistry;
use OpenCCK\App\Mcp\McpManager;
use OpenCCK\App\Mcp\McpSecrets;
use OpenCCK\App\Mcp\McpTool;
use OpenCCK\App\Vault\Vault;
use OpenCCK\Domain\Agent\ParticipantPolicy;
use OpenCCK\Domain\Agent\RunLimits;
use OpenCCK\Domain\Entity\Member;
use OpenCCK\Domain\Entity\MemberKind;
use OpenCCK\Domain\Entity\Role;
use OpenCCK\Domain\Mcp\McpServer;
use OpenCCK\Test\Support\InMemoryMcpServerRepository;
use OpenCCK\Test\Support\InMemorySecretRepository;
use Psr\Log\NullLogger;

/**
 * spec: brief/servers.md#ssh-как-предустановленный-mcp — the trusted host key goes to the process at its start; the
 * tools of our MCP are wrapped by the core; the core calls a tool of an entry and reads its structured answer
 */
final class McpSshHooksTest extends AsyncTestCase {
    private InMemoryMcpServerRepository $servers;
    private ToolRegistry $registry;
    private McpManager $manager;
    private Vault $vault;
    /** @var \ArrayObject<int, string> the command lines the client was called with */
    private \ArrayObject $started;

    protected function setUp(): void {
        parent::setUp();
        $this->servers = new InMemoryMcpServerRepository();
        $this->registry = new ToolRegistry();
        $this->started = new \ArrayObject();
        $client = new class ($this->started) implements McpClient {
            /** @param \ArrayObject<int, string> $started */
            public function __construct(private readonly \ArrayObject $started) {}

            public function tools(McpEndpoint $server, ?string $token, Cancellation $cancellation): array {
                return [['name' => 'run', 'description' => 'runs', 'schema' => ['type' => 'object'], 'annotations' => []]];
            }

            public function resources(McpEndpoint $server, ?string $token, Cancellation $cancellation): array {
                return [];
            }

            public function readResource(McpEndpoint $server, ?string $token, string $uri, Cancellation $cancellation): string {
                return '';
            }

            public function call(McpEndpoint $server, ?string $token, string $tool, array $arguments, Cancellation $cancellation): array {
                $this->started->append($server->endpointUrl());
                return ['exit 0', false, [], ['exit' => 0, 'stdout' => 'secret-pass-1 here', 'stderr' => '', 'cut' => false]];
            }
        };
        $this->vault = new Vault(new InMemorySecretRepository(), random_bytes(32));
        $owner = new Member(1, MemberKind::Human, Role::admin(), 'Owner');
        $this->vault->put('pass', 'password', 'secret-pass-1', $owner);
        $this->manager = new McpManager($this->servers, $client, $this->vault, $this->registry, static fn(string $c): Member => $owner, static function (string $t): void {
        }, new NullLogger());
        $this->manager->useSecrets(new McpSecrets($this->vault, static fn(int $p): ?int => null, new \OpenCCK\Test\Support\InMemorySecretInheritanceRepository()));
        $entry = ['command' => 'php', 'args' => ['/workspace/mcp/ssh/current/bin/ssh-mcp', '--host=srv', '--user=u'], 'env' => ['SSH_PASSWORD' => '{pass}']];
        $this->servers->add('member:1', 'base', 'stdio:php', null);
        $this->servers->setApproval('member:1', 'base', 'h', 1, $entry);
    }

    public function testTheKeyArgumentsGoToTheProcessAndTheAnswerComesMasked(): void {
        $this->manager->useSsh(static fn(McpServer $s): array => $s->name === 'base' ? ['--host-key=SHA256:k'] : [], null);
        $this->manager->approve('member:1', 'base', new NullCancellation());

        [$text, $error, $structured] = $this->manager->call('member:1', 'base', 'run', ['command' => 'id'], new NullCancellation());

        self::assertStringContainsString("'--host-key=SHA256:k'", implode("
", $this->started->getArrayCopy()));
        self::assertSame(['exit 0', false], [$text, $error]);
        self::assertSame('*** here', $structured['stdout'] ?? null, 'a value put into the entry is masked in the fields too');
    }

    public function testTheToolsOfAnEntryAreWrappedByTheCore(): void {
        /** @var \ArrayObject<int, string> $wrapped */
        $wrapped = new \ArrayObject();
        $this->manager->useSsh(null, static function (McpServer $server, McpTool $tool, string $remote) use ($wrapped): Tool {
            $wrapped->append($server->key() . ' ' . $remote);
            return $tool;
        });

        $this->manager->approve('member:1', 'base', new NullCancellation());

        self::assertSame(['member:1|base run'], $wrapped->getArrayCopy());
        $policy = (new ParticipantPolicy(null, new RunLimits(30, 1000, 900, 120), null, true))->withMcpContext('member:1', open: true);
        self::assertNotNull($this->registry->get('mcp_base_run', $policy));
    }

    public function testACallOfAnEntryThatIsNotOnIsRefused(): void {
        $this->expectException(\DomainException::class);

        $this->manager->call('member:1', 'nothing', 'run', [], new NullCancellation());
    }
}
