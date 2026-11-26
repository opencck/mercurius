<?php

declare(strict_types=1);

namespace OpenCCK\Test\Unit\App\Peer;

use Amp\NullCancellation;
use Amp\PHPUnit\AsyncTestCase;
use Mercurius\Peer\AgentIdentity;
use OpenCCK\App\Agent\Tool\Agents\PeerDirectoryTool;
use OpenCCK\App\Agent\Tool\ToolContext;
use OpenCCK\App\Peer\A2aServer;
use OpenCCK\App\Peer\PeerDirectory;
use OpenCCK\Domain\Agent\DangerClass;
use OpenCCK\Domain\Entity\Member;
use OpenCCK\Domain\Entity\Permission;
use OpenCCK\Domain\Entity\Role;
use OpenCCK\Test\Support\AgentStack;
use OpenCCK\Test\Support\InMemoryPeerRepository;
use OpenCCK\Test\Support\ScriptedLlm;
use Psr\Log\NullLogger;

/**
 * The directory of the network: who the agent knows, who is connected, how to reach them — for a role with the
 * permission peers, without tokens and their secrets; an agent the owner hid is not shown, but counted.
 *
 * spec: brief/network.md#справочник-сети
 */
final class PeerDirectoryTest extends AsyncTestCase {
    private AgentStack $stack;
    private InMemoryPeerRepository $peers;
    private Member $owner;
    private Member $scout;
    private Member $plain;
    private AgentIdentity $north;
    private AgentIdentity $south;

    protected function setUp(): void {
        parent::setUp();
        $this->stack = new AgentStack(new ScriptedLlm([ScriptedLlm::text('Готово.')]));
        $this->owner = $this->stack->principals->ensureOwner('telegram', '42', 'Owner');
        Role::define('scout', 'Разведчик', 50, [Permission::Tools, Permission::Peers]);
        $this->scout = $this->stack->principals->createAgent('scout', Role::of('scout'));
        $this->plain = $this->stack->principals->createAgent('plain', Role::user());
        $this->peers = new InMemoryPeerRepository();
        $this->north = AgentIdentity::generate();
        $this->south = AgentIdentity::generate();
        $card = ['mercurius' => ['transport' => 'ws', 'ws' => 'wss://north.example/a2a/ws', 'publicKey' => $this->north->publicKey]];
        $this->peers->addPeer('north', 'https://north.example', 'peer-north-token', $this->north->publicKey, $card);
        $this->peers->addPeer('south', 'https://south.example', 'peer-south-token', $this->south->publicKey, null);
        $this->peers->allowKey($this->scout->id, 'scout', 'SHA256:' . str_repeat('a', 64));
        $this->peers->setHidden('south', true);
    }

    protected function tearDown(): void {
        Role::reset();
        parent::tearDown();
    }

    private function directory(): PeerDirectory {
        return new PeerDirectory(
            $this->peers,
            static fn(): array => [['direction' => 'in', 'name' => 'scout', 'fingerprint' => 'SHA256:' . str_repeat('a', 64), 'opened' => microtime(true) - 120.0]],
            static fn(): array => ['name' => 'Self', 'url' => 'https://self.example/.well-known/agent-card.json', 'transport' => 'ws', 'ws' => 'wss://self.example/a2a/ws', 'fingerprint' => 'SHA256:self', 'publicKey' => 'PEM'],
        );
    }

    public function testAnAgentWithThePermissionSeesTheVisibleNeighboursAndHowManyThereAre(): void {
        $seen = $this->directory()->of($this->scout);
        $json = (string) json_encode($seen);

        self::assertSame(['north'], array_column($seen['neighbours'], 'name'), 'a hidden agent is not shown');
        self::assertSame('wss://north.example/a2a/ws', $seen['neighbours'][0]['ws']);
        self::assertSame(AgentIdentity::fingerprintOf($this->north->publicKey), $seen['neighbours'][0]['fingerprint']);
        self::assertSame($this->north->publicKey, $seen['neighbours'][0]['publicKey']);
        self::assertSame(['scout'], array_column($seen['connected'], 'name'));
        self::assertSame(['total' => 3, 'visible' => 2], $seen['counts'], 'north, south and scout are known; south is hidden');
        self::assertArrayNotHasKey('allowed', $seen, 'whom the agent lets in is not told to agents');
        self::assertSame('wss://self.example/a2a/ws', $seen['self']['ws']);
        self::assertStringNotContainsString('peer-north-token', $json, 'the secret of a token is never told');
        self::assertStringNotContainsString('south', $json);
    }

    public function testTheOwnerSeesTheHiddenOnesMarkedAndWhomTheAgentLetsIn(): void {
        $seen = $this->directory()->of($this->owner);

        self::assertSame(['north', 'south'], array_column($seen['neighbours'], 'name'));
        self::assertSame([false, true], array_column($seen['neighbours'], 'hidden'));
        self::assertSame(['scout'], array_column($seen['allowed'] ?? [], 'name'));
        self::assertSame(['total' => 3, 'visible' => 2], $seen['counts']);
    }

    public function testTheToolAndTheMethodAreForThePermissionOnly(): void {
        $tool = new PeerDirectoryTool($this->directory());
        $context = static fn(Member $who): ToolContext => new ToolContext(1, 1, $who, new NullCancellation());

        self::assertSame(DangerClass::Read, $tool->danger([]));
        self::assertTrue($tool->execute([], $context($this->plain))->isError);
        self::assertStringContainsString('wss://north.example/a2a/ws', $tool->execute([], $context($this->scout))->content);

        $server = new A2aServer($this->peers, $this->stack->principals, $this->stack->loop, $this->stack->runs, $this->stack->transcript, $this->stack->conversations, $this->stack->policies, 'test/model', 'https://self.example', 'Self', 'test', new NullLogger(), AgentIdentity::generate(), 'entrance-token-entrance-token');
        $server->useDirectory($this->directory());
        [, $refused] = $server->handle($this->plain, ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ListPeers', 'params' => []]);
        [, $answered] = $server->handle($this->scout, ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'ListPeers', 'params' => []]);

        self::assertArrayHasKey('error', $refused);
        $result = $answered['result'] ?? null;
        self::assertIsArray($result);
        self::assertSame(['total' => 3, 'visible' => 2], $result['counts'] ?? null);
    }

    public function testThePermissionBringsTheTool(): void {
        self::assertTrue($this->stack->policies->forPrincipal($this->scout)->allowsTool(PeerDirectoryTool::NAME));
        self::assertFalse($this->stack->policies->forPrincipal($this->plain)->allowsTool(PeerDirectoryTool::NAME));
    }
}
