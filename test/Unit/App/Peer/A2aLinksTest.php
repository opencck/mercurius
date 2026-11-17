<?php

declare(strict_types=1);

namespace OpenCCK\Test\Unit\App\Peer;

use Amp\Http\Client\HttpClient;
use Amp\PHPUnit\AsyncTestCase;
use OpenCCK\App\Peer\A2aClient;
use OpenCCK\App\Peer\A2aLinks;
use OpenCCK\App\Peer\A2aServer;
use OpenCCK\App\Peer\AgentIdentity;
use OpenCCK\App\Peer\PeerNetwork;
use OpenCCK\App\Vault\Vault;
use OpenCCK\Domain\Entity\Principal;
use OpenCCK\Domain\Entity\Role;
use OpenCCK\Domain\Peer\A2aTask;
use OpenCCK\Test\Support\A2aWire;
use OpenCCK\Test\Support\AgentStack;
use OpenCCK\Test\Support\InMemoryPeerRepository;
use OpenCCK\Test\Support\InMemorySecretRepository;
use OpenCCK\Test\Support\InProcessA2aChannel;
use OpenCCK\Test\Support\ScriptedLlm;
use Psr\Log\NullLogger;

use function Amp\async;
use function Amp\delay;

/**
 * Connections between agents are listed on both sides and go both ways: an agent that connected here can be given a
 * task over its own connection, and a live connection is used instead of opening another.
 */
final class A2aLinksTest extends AsyncTestCase {
    /** @var array<string, array{stack: AgentStack, server: A2aServer, network: PeerNetwork, links: A2aLinks, key: AgentIdentity, owner: Principal, peers: InMemoryPeerRepository}> */
    private array $agents = [];
    public int $connects = 0;

    private function agent(string $name, string $answer): void {
        $stack = new AgentStack(new ScriptedLlm([ScriptedLlm::text($answer), ScriptedLlm::text($answer)]));
        $owner = $stack->principals->ensureOwner('telegram', $name, 'Owner ' . $name);
        $vault = new Vault(new InMemorySecretRepository(), random_bytes(32));
        $vault->put('entrance', 'token', 'entrance', $owner);
        $key = AgentIdentity::generate();
        $peers = new InMemoryPeerRepository();
        $links = new A2aLinks();
        $url = 'https://' . $name . '.example';
        $server = new A2aServer($peers, $stack->principals, $stack->loop, $stack->runs, $stack->transcript, $stack->conversations, $stack->policies, 'test/model', $url, $name, 'test', new NullLogger(), $key, 'entrance', transport: 'ws', links: $links);
        $stack->scheduler->useJobRuns($server);
        $test = $this;
        $connect = function (string $wsUrl, string $token) use ($test): InProcessA2aChannel {
            $test->connects++;
            $target = $test->agents[parse_url($wsUrl, PHP_URL_HOST) === 'b.example' ? 'b' : 'a']['server'];
            [$mine, $theirs] = InProcessA2aChannel::pair();
            async(static fn() => $target->serveSocket($theirs))->ignore();
            return $mine;
        };
        $client = new A2aClient(new HttpClient(new A2aWire($server), []), $key, null, $connect, $links, $server->handlerFor(...));
        $network = new PeerNetwork($peers, $stack->principals, $vault, $client, $stack->conversations, $stack->transcript, $stack->channel, $url, new NullLogger(), $key, static fn(): Principal => $owner, null, $links);
        $this->agents[$name] = ['stack' => $stack, 'server' => $server, 'network' => $network, 'links' => $links, 'key' => $key, 'owner' => $owner, 'peers' => $peers];
    }

    protected function setUp(): void {
        parent::setUp();
        $this->agent('a', 'A: готово');
        $this->agent('b', 'B: четыре');
        // each owner lets the other agent in; only a knows b as a neighbour to connect to
        $this->agents['b']['network']->allow('agent-a', $this->agents['a']['key']->fingerprint(), Role::User);
        $this->agents['a']['network']->allow('agent-b', $this->agents['b']['key']->fingerprint(), Role::User);
        $cardOfB = $this->agents['b']['server']->card();
        $this->agents['a']['peers']->addPeer('b', 'https://b.example', 'entrance', $this->agents['b']['key']->publicKey, $cardOfB);
    }

    public function testAConnectionIsListedOnBothSides(): void {
        $this->agents['a']['network']->open('b');

        $out = $this->agents['a']['links']->all();
        $in = $this->agents['b']['links']->all();
        self::assertSame([[A2aLinks::OUT, 'b']], array_map(static fn(array $l): array => [$l['direction'], $l['name']], $out));
        self::assertSame([[A2aLinks::IN, 'agent-a']], array_map(static fn(array $l): array => [$l['direction'], $l['name']], $in));
        self::assertStringContainsString('agent-a', $this->agents['b']['network']->text(), 'the owner of b sees who is connected');
    }

    public function testTheAgentThatConnectedIsGivenATaskOverItsOwnConnection(): void {
        $this->agents['a']['network']->open('b');
        $b = $this->agents['b'];

        $task = $b['network']->delegate($b['owner'], 'agent-a', 'Сделай', 'telegram', 'b', 0, []);
        for ($i = 0; $i < 20 && ($b['peers']->outgoing[$task->id]->state ?? '') !== A2aTask::COMPLETED; $i++) {
            delay(0.02);
            $b['network']->poll();
        }

        self::assertSame(A2aTask::COMPLETED, $b['peers']->outgoing[$task->id]->state ?? null, 'b knows no address of a: the connection a opened carries the task');
        self::assertStringContainsString('A: готово', implode("\n", $b['stack']->channel->texts('send')));
        self::assertSame(1, $this->connects);
    }

    public function testALiveConnectionIsUsedInsteadOfAnotherAndCanBeClosed(): void {
        $a = $this->agents['a'];
        $a['network']->open('b');
        $a['network']->delegate($a['owner'], 'b', 'Посчитай', 'telegram', 'a', 0, []);
        $a['network']->delegate($a['owner'], 'b', 'Ещё', 'telegram', 'a', 0, []);

        self::assertSame(1, $this->connects, 'one connection carries every task');
        $all = $a['links']->all();
        $id = $all === [] ? 0 : $all[0]['id'];
        self::assertTrue($a['network']->closeLink($id));
        delay(0.02);
        self::assertSame([], $a['links']->all());
        self::assertSame([], $this->agents['b']['links']->all(), 'the other side sees it closed');
    }

    public function testAParticipantTheOwnerGaveTheToolOpensAConnectionToo(): void {
        $a = $this->agents['a'];
        $guest = $a['stack']->principals->createAgent('rekryt', Role::Admin);
        $tool = new \OpenCCK\App\Agent\Tool\PeerLinksTool($a['network']);
        $context = new \OpenCCK\App\Agent\Tool\ToolContext(1, 1, $guest, new \Amp\NullCancellation());

        $result = $tool->execute(['action' => 'open', 'peer' => 'b'], $context);

        self::assertFalse($result->isError, 'the policy decides who has the tool, not the tool itself: ' . $result->content);
        self::assertCount(1, $a['links']->all());
    }
}
