<?php

declare(strict_types=1);

namespace OpenCCK\Test\Unit\App\Mcp;

use Amp\Cancellation;
use Amp\NullCancellation;
use Amp\PHPUnit\AsyncTestCase;
use Mercurius\Mcp\McpClient;
use Mercurius\Mcp\McpEndpoint;
use Mercurius\Mcp\McpServerInfo;
use OpenCCK\App\Agent\Model\ModelCatalogView;
use OpenCCK\App\Agent\Model\ProviderRegistry;
use OpenCCK\App\Agent\Tool\ToolRegistry;
use OpenCCK\App\Mcp\McpManager;
use OpenCCK\App\Mcp\McpModelProviders;
use OpenCCK\App\Vault\Vault;
use OpenCCK\Domain\Entity\Member;
use OpenCCK\Domain\Entity\MemberKind;
use OpenCCK\Domain\Entity\Role;
use OpenCCK\Domain\Mcp\McpServer;
use OpenCCK\Test\Support\InMemoryMcpServerRepository;
use OpenCCK\Test\Support\InMemoryModelStores;
use OpenCCK\Test\Support\InMemorySecretRepository;
use Psr\Log\NullLogger;

/**
 * A server of the owners' file that declares the contract of models becomes a provider: its models go into the catalog,
 * a name already taken keeps the server off, and a server that went off leaves its provider known but unavailable.
 *
 * spec: brief/skills-mcp.md#провайдеры-моделей-от-mcp
 */
final class McpModelProvidersTest extends AsyncTestCase {
    private InMemoryMcpServerRepository $servers;
    private ProviderRegistry $registry;
    private InMemoryModelStores $catalog;
    private McpManager $manager;
    private McpModelProviders $providers;
    /** @var \ArrayObject<int, string> */
    private \ArrayObject $told;

    private static function client(bool $declares): McpClient {
        return new class ($declares) implements McpClient, McpServerInfo {
            public function __construct(private readonly bool $declares) {}

            public function serverCapabilities(McpEndpoint $server, ?string $token, Cancellation $cancellation): array {
                return $this->declares ? ['tools' => [], 'experimental' => ['mercurius.models' => ['version' => 1]]] : ['tools' => []];
            }

            public function tools(McpEndpoint $server, ?string $token, Cancellation $cancellation): array {
                return array_map(static fn(string $n): array => ['name' => $n, 'description' => $n, 'schema' => ['type' => 'object'], 'annotations' => []], ['models_list', 'image', 'speech']);
            }

            public function resources(McpEndpoint $server, ?string $token, Cancellation $cancellation): array {
                return [];
            }

            public function readResource(McpEndpoint $server, ?string $token, string $uri, Cancellation $cancellation): string {
                return '';
            }

            public function call(McpEndpoint $server, ?string $token, string $tool, array $arguments, Cancellation $cancellation): array {
                if ($tool === 'models_list') {
                    return ['', false, [], ['models' => [
                        ['id' => 'pic-1', 'kind' => 'image', 'tool' => 'image', 'default' => true, 'price' => ['unit' => 'call', 'usd' => 0.04]],
                        ['id' => 'tts-1', 'kind' => 'voice', 'tool' => 'speech'],
                        ['id' => 'ghost', 'kind' => 'image', 'tool' => 'missing'],
                    ]]];
                }
                return ['drawn', false, [['name' => 'a.png', 'type' => 'image/png', 'bytes' => 'PNG']], null];
            }
        };
    }

    /** @param list<string> $reserved */
    private function manager(bool $declares, array $reserved = ModelCatalogView::KNOWN): void {
        $this->servers = new InMemoryMcpServerRepository();
        $this->registry = new ProviderRegistry($reserved);
        $this->catalog = new InMemoryModelStores();
        $this->told = new \ArrayObject();
        $owner = new Member(1, MemberKind::Human, Role::admin(), 'Owner');
        $told = $this->told;
        $this->manager = new McpManager($this->servers, self::client($declares), new Vault(new InMemorySecretRepository(), random_bytes(32)), new ToolRegistry(), static fn(string $c): Member => $owner, static function (string $t) use ($told): void {
            $told->append($t);
        }, new NullLogger());
        $this->providers = new McpModelProviders($this->registry, $this->catalog, new NullLogger());
        $this->manager->useModelProviders($this->providers);
    }

    public function testAServerOfTheOwnersThatDeclaresModelsBecomesAProvider(): void {
        $this->manager(true);
        $this->servers->add('owners', 'pics', 'https://pics.example/mcp', null);

        $this->manager->approve('owners', 'pics', new NullCancellation());

        self::assertSame(['mcp-pics'], $this->registry->available());
        self::assertSame(['pic-1', 'tts-1'], array_map(static fn($m): string => $m->id, $this->catalog->models('mcp-pics')), 'a model its server cannot call is left out');
        self::assertSame('voice', $this->registry->model('mcp-pics', 'tts-1')?->kind);
        [$text, $error, , $files] = $this->providers->call('mcp-pics', 'image', ['prompt' => 'cat'], new NullCancellation());
        self::assertSame(['drawn', false, 'a.png'], [$text, $error, $files[0]['name'] ?? null], 'a model is called by the tool of its server, with the files of the answer');
    }

    public function testTheSameServerOfAMemberOrWithoutTheDeclarationIsAnOrdinaryOne(): void {
        $this->manager(true);
        $this->servers->add('member:1', 'pics', 'https://pics.example/mcp', null);
        $this->manager->approve('member:1', 'pics', new NullCancellation());
        self::assertSame([], $this->registry->all(), 'a member may not put a model into the agent\'s lists');

        $this->manager(false);
        $this->servers->add('owners', 'pics', 'https://pics.example/mcp', null);
        $this->manager->approve('owners', 'pics', new NullCancellation());
        self::assertSame([], $this->registry->all(), 'no declaration — no provider');
        self::assertSame(McpServer::CONNECTED, $this->servers->find('owners', 'pics')?->status, 'and the server works as before');
    }

    public function testATakenNameKeepsTheServerOffAndAServerThatWentOffLeavesItsProviderUnavailable(): void {
        $this->manager(true, [...ModelCatalogView::KNOWN, 'mcp-pics']);
        $this->servers->add('owners', 'pics', 'https://pics.example/mcp', null);

        $this->manager->approve('owners', 'pics', new NullCancellation());

        self::assertSame(McpServer::NAMES, $this->servers->find('owners', 'pics')?->status, 'the server is not turned on');
        self::assertSame([], $this->registry->available());
        self::assertStringContainsString('mcp-pics', implode("\n", $this->told->getArrayCopy()), 'the owners hear why');

        $this->manager(true);
        $this->servers->add('owners', 'pics', 'https://pics.example/mcp', null);
        $this->manager->approve('owners', 'pics', new NullCancellation());
        $this->manager->disable('owners', 'pics');

        self::assertSame([], $this->registry->available());
        self::assertArrayHasKey('mcp-pics', $this->registry->all(), 'known, with why');
        self::assertNotSame('', $this->registry->reason('mcp-pics'));

        $this->manager->remove('owners', 'pics');
        self::assertSame([], $this->registry->all());
    }
}
