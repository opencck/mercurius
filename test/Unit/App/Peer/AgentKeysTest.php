<?php

declare(strict_types=1);

namespace OpenCCK\Test\Unit\App\Peer;

use Amp\Http\Client\HttpClient;
use Amp\PHPUnit\AsyncTestCase;
use OpenCCK\App\Peer\A2aClient;
use OpenCCK\App\Peer\A2aServer;
use OpenCCK\App\Peer\AgentIdentity;
use OpenCCK\App\Peer\AgentKeys;
use OpenCCK\App\Peer\PeerNetwork;
use OpenCCK\App\Vault\Vault;
use OpenCCK\Domain\Entity\Principal;
use OpenCCK\Domain\Entity\Role;
use OpenCCK\Test\Support\A2aWire;
use OpenCCK\Test\Support\AgentStack;
use OpenCCK\Test\Support\InMemoryPeerRepository;
use OpenCCK\Test\Support\InMemorySecretRepository;
use OpenCCK\Test\Support\InProcessA2aChannel;
use OpenCCK\Test\Support\ScriptedLlm;
use Psr\Log\NullLogger;

use function Amp\async;

/**
 * An agent may present itself to each neighbour by a key of its own choice; it makes the keys itself. The transport
 * is the one the neighbour's card names.
 */
final class AgentKeysTest extends AsyncTestCase {
    private const ENTRANCE = 'entrance';

    private Vault $vault;
    private Principal $owner;
    private AgentIdentity $main;
    private AgentStack $follower;
    private InMemoryPeerRepository $followerPeers;
    private PeerNetwork $followerNetwork;

    private function server(string $transport): A2aServer {
        $this->follower = new AgentStack(new ScriptedLlm([ScriptedLlm::text('ok')]));
        $this->follower->principals->ensureOwner('telegram', '42', 'Owner');
        $this->followerPeers = new InMemoryPeerRepository();
        $key = AgentIdentity::generate();
        $server = new A2aServer($this->followerPeers, $this->follower->principals, $this->follower->loop, $this->follower->runs, $this->follower->transcript, $this->follower->conversations, $this->follower->policies, 'test/model', 'https://follower.example', 'Follower', 'test', new NullLogger(), $key, self::ENTRANCE, transport: $transport);
        $this->followerNetwork = new PeerNetwork($this->followerPeers, $this->follower->principals, new Vault(new InMemorySecretRepository(), random_bytes(32)), new A2aClient(new HttpClient(new A2aWire($server), []), $key), $this->follower->conversations, $this->follower->transcript, $this->follower->channel, 'https://follower.example', new NullLogger(), $key, static fn(): ?Principal => null);
        return $server;
    }

    /** @return array{PeerNetwork, AgentKeys, InMemoryPeerRepository} the leader */
    private function leader(A2aServer $server): array {
        $stack = new AgentStack(new ScriptedLlm([]));
        $this->owner = $stack->principals->ensureOwner('telegram', '7', 'Leader');
        $this->vault = new Vault(new InMemorySecretRepository(), random_bytes(32));
        $this->vault->put('follower-entrance', 'token', self::ENTRANCE, $this->owner);
        $this->main = AgentIdentity::load($this->vault, $this->owner);
        $keys = new AgentKeys($this->vault, fn(): Principal => $this->owner, $this->main);
        $connect = static function (string $url, string $token) use ($server): InProcessA2aChannel {
            [$mine, $theirs] = InProcessA2aChannel::pair();
            async(static fn() => $server->serveSocket($theirs))->ignore();
            return $mine;
        };
        $peers = new InMemoryPeerRepository();
        $network = new PeerNetwork($peers, $stack->principals, $this->vault, new A2aClient(new HttpClient(new A2aWire($server), []), $this->main, $keys, $connect), $stack->conversations, $stack->transcript, $stack->channel, 'https://leader.example', new NullLogger(), $this->main, fn(): Principal => $this->owner, $keys);
        return [$network, $keys, $peers];
    }

    public function testKeysAreMadeOnceAndListed(): void {
        $this->leader($this->server('http'));
        $keys = new AgentKeys($this->vault, fn(): Principal => $this->owner, $this->main);

        $alt = $keys->get('work');

        self::assertSame($alt->fingerprint(), $keys->get('work')->fingerprint(), 'kept in the vault, not made again');
        self::assertNotSame($this->main->fingerprint(), $alt->fingerprint());
        self::assertSame($this->main->fingerprint(), $keys->get('')->fingerprint());
        self::assertSame(['' => $this->main->fingerprint(), 'work' => $alt->fingerprint()], array_column($keys->list(), 'fingerprint', 'name'));
        $this->expectException(\DomainException::class);
        $keys->get('Bad Name');
    }

    public function testANeighbourKnowsTheAgentByTheKeyChosenForIt(): void {
        $server = $this->server('http');
        [$network, $keys] = $this->leader($server);
        $network->add($this->owner, 'follower', 'https://follower.example', 'follower-entrance', 'work');
        $this->followerNetwork->allow('leader', $this->main->fingerprint(), Role::User);

        $refused = $this->delegate($network);
        self::assertStringContainsString($keys->get('work')->fingerprint(), $refused, 'the follower sees the chosen key, not the main one');

        $this->followerNetwork->allow('leader-work', $keys->get('work')->fingerprint(), Role::User);
        self::assertStringContainsString('отдана соседу', $this->delegate($network));

        self::assertStringContainsString('follower', $network->useKey('follower', ''));
        self::assertStringContainsString('отдана соседу', $this->delegate($network), 'back to the main key, let in too');
    }

    public function testAFollowerOnWebsocketIsReachedThereAndNotByHttp(): void {
        $server = $this->server('ws');
        [$network, , $peers] = $this->leader($server);
        $this->followerNetwork->allow('leader', $this->main->fingerprint(), Role::User);

        $network->add($this->owner, 'follower', 'https://follower.example', 'follower-entrance');

        $card = $server->card();
        self::assertSame('wss://follower.example/a2a/ws', A2aClient::socketUrl(new \OpenCCK\Domain\Peer\Peer('x', '', '', '', $card)));
        self::assertStringContainsString('отдана соседу', $this->delegate($network), 'the task went over the socket');
        self::assertSame('ws', A2aClient::transport($peers->peer('follower') ?? throw new \LogicException()));
    }

    public function testANeighbourThatMovedToWebsocketIsFollowedByItsCard(): void {
        $server = $this->server('ws');
        [$network, , $peers] = $this->leader($server);
        $this->followerNetwork->allow('leader', $this->main->fingerprint(), Role::User);
        $card = $server->card();
        $card['mercurius'] = ['publicKey' => $this->followerKeyOf($server)];
        $peers->addPeer('follower', 'https://follower.example', 'follower-entrance', $this->followerKeyOf($server), $card);
        self::assertSame('http', A2aClient::transport($peers->peer('follower') ?? throw new \LogicException()), 'added before cards named the transport');

        self::assertStringContainsString('отдана соседу', $this->delegate($network), 'POST /a2a is gone: the card is read again');
        $after = $peers->peers()[0] ?? null;
        self::assertNotNull($after);
        self::assertSame('ws', A2aClient::transport($after));
    }

    public function testACardWithAnotherKeyIsNotTakenOnRefresh(): void {
        $server = $this->server('ws');
        [$network, , $peers] = $this->leader($server);
        $peers->addPeer('follower', 'https://follower.example', 'follower-entrance', AgentIdentity::generate()->publicKey, ['name' => 'old']);

        self::assertStringNotContainsString('отдана соседу', $this->delegate($network));
        self::assertSame('http', A2aClient::transport($peers->peer('follower') ?? throw new \LogicException()), 'the pinned key decides, not the card');
    }

    private function followerKeyOf(A2aServer $server): string {
        return $server->identity()->publicKey;
    }

    private function delegate(PeerNetwork $network): string {
        try {
            $task = $network->delegate($this->owner, 'follower', 'задача', 'telegram', '7', 0, []);
            return 'Задача #' . $task->id . ' отдана соседу';
        } catch (\Throwable $e) {
            return $e->getMessage();
        }
    }
}
