<?php

declare(strict_types=1);

namespace OpenCCK\Test\Unit\App\Peer;

use Amp\PHPUnit\AsyncTestCase;
use OpenCCK\App\Peer\A2aServer;
use Mercurius\Peer\A2aSocket;
use Mercurius\Peer\AgentIdentity;
use OpenCCK\App\Peer\PeerNetwork;
use OpenCCK\App\Vault\Vault;
use OpenCCK\Domain\Entity\Member;
use OpenCCK\Domain\Entity\Role;
use OpenCCK\Domain\Peer\A2aTask;
use OpenCCK\Test\Support\AgentStack;
use OpenCCK\Test\Support\InMemoryPeerRepository;
use OpenCCK\Test\Support\InMemorySecretRepository;
use OpenCCK\Test\Support\InProcessA2aChannel;
use OpenCCK\Test\Support\ScriptedLlm;
use Psr\Log\NullLogger;
use RuntimeException;

use function Amp\async;
use function Amp\delay;

/**
 * Agents over one connection: the entrance is passed before (the token), then the follower sends a random number, the
 * leader signs it, and the follower signs the leader's number back; then plain JSON-RPC of A2A.
 */
final class A2aSocketTest extends AsyncTestCase {
    private const FOLLOWER = 'https://follower.example';
    private A2aServer $server;
    private AgentIdentity $leaderKey;
    private AgentIdentity $followerKey;
    private \OpenCCK\Test\Support\FakeSandbox $sandbox;
    private \OpenCCK\Test\Support\FakeChannel $ownerChat;
    private \OpenCCK\App\Peer\AgentRequests $requests;
    private AgentStack $follower;

    protected function setUp(): void {
        parent::setUp();
        $this->leaderKey = AgentIdentity::generate();
        $this->followerKey = AgentIdentity::generate();
        $follower = new AgentStack(new ScriptedLlm([ScriptedLlm::text('Париж.')]));
        $follower->principals->ensureOwner('telegram', '42', 'Owner');
        $peers = new InMemoryPeerRepository();
        $this->sandbox = new \OpenCCK\Test\Support\FakeSandbox();
        // agents of this follower get a sandbox: files from a neighbour are kept there
        $policies = new \OpenCCK\App\Service\PolicyService(new \OpenCCK\Test\Support\InMemoryPolicyRepository(), new \OpenCCK\Domain\Agent\RunLimits(30, 100_000, 900, 120), new \OpenCCK\Domain\Agent\SandboxLimits(100_000_000, 64, 60, 1_000_000_000, 4));
        $this->ownerChat = $follower->channel;
        $this->follower = $follower;
        $this->requests = new \OpenCCK\App\Peer\AgentRequests($peers, $follower->principals, $follower->channel, '42', retryDelays: []);
        $this->server = new A2aServer($peers, $follower->principals, $follower->loop, $follower->runs, $follower->transcript, $follower->conversations, $policies, 'test/model', 'https://follower.example', 'Follower', 'test', new NullLogger(), $this->followerKey, 'entrance', sandbox: $this->sandbox, requests: $this->requests);
        $follower->scheduler->useJobRuns($this->server);
        $network = new PeerNetwork($peers, $follower->principals, new Vault(new InMemorySecretRepository(), random_bytes(32)), new \Mercurius\Peer\A2aClient(new \Amp\Http\Client\HttpClient(new \OpenCCK\Test\Support\FakeHttpClient(), []), $this->followerKey), $follower->conversations, $follower->transcript, $follower->channel, 'https://follower.example', new NullLogger(), $this->followerKey, static fn(): ?Member => null);
        $network->allow('leader', $this->leaderKey->fingerprint(), Role::user());
    }

    /** @return array{InProcessA2aChannel, InProcessA2aChannel} the leader's end and the follower's end, served */
    private function connection(float $timeout = 5.0): array {
        [$leader, $follower] = InProcessA2aChannel::pair();
        async(fn() => $this->server->serveSocket($follower, $timeout))->ignore();
        return [$leader, $follower];
    }

    public function testAKnownAgentProvesItsKeyAndHandsOverATask(): void {
        [$end] = $this->connection();

        $socket = A2aSocket::open($end, $this->leaderKey, $this->followerKey->publicKey, audience: self::FOLLOWER);
        $sent = $socket->request('SendMessage', ['message' => ['messageId' => 'm1', 'parts' => [['text' => 'Столица Франции?']]]]);
        $id = self::field($sent, 'task', 'id');
        self::assertNotSame('', $id);
        $state = '';
        for ($i = 0; $i < 20 && $state !== A2aTask::COMPLETED; $i++) {
            delay(0.02);
            $task = $socket->request('GetTask', ['id' => $id]);
            $state = self::field($task, 'status', 'state');
        }

        self::assertSame(A2aTask::COMPLETED, $state);
        $asked = array_values(array_filter($this->follower->transcript->messages, static fn(array $m): bool => str_contains($m['message']->content, 'Столица Франции?')));
        self::assertStringContainsString('сам подключился ко мне по WebSocket', $asked[0]['message']->content ?? '', 'the agent knows who gave the task and how');
        self::assertStringContainsString('«leader»', $asked[0]['message']->content ?? '');
        self::assertSame(['hello', 'auth'], array_slice(array_map(static fn(string $f): string => self::field(self::decoded($f), 'type'), $end->sent), 0, 2));
        $socket->close();
    }

    public function testAnAgentThatProvedItsKeyGetsTheExtendedCard(): void {
        // spec: brief/network.md#опознание-агентов-ключи-ecdsa — the extended card lists the skills of the agent's policy, after the key
        $this->server->useSkills(static fn(\OpenCCK\Domain\Entity\Member $agent): array => [['id' => 'web_search', 'name' => 'web_search', 'description' => 'Ищет в сети']]);
        $capabilities = $this->server->card()['capabilities'] ?? [];
        self::assertTrue(is_array($capabilities) && ($capabilities['extendedAgentCard'] ?? false) === true, 'the public card says there is more');
        [$end] = $this->connection();

        $socket = A2aSocket::open($end, $this->leaderKey, $this->followerKey->publicKey, audience: self::FOLLOWER);
        $card = $socket->request('GetExtendedAgentCard', []);

        self::assertSame('web_search', self::field($card, 'skills', '0', 'id'));
        $socket->close();
    }

    public function testFilesOfANeighbourAreKeptInItsAgentsSandbox(): void {
        [$end] = $this->connection();
        $socket = A2aSocket::open($end, $this->leaderKey, $this->followerKey->publicKey, audience: self::FOLLOWER);

        $sent = $socket->request('SendMessage', \Mercurius\Peer\A2aClient::message('m-files', 'Посмотри QR', 0, [], [['name' => 'qr.png', 'bytes' => 'QRPNG'], ['name' => '../../etc/passwd', 'bytes' => 'x']]));

        $task = self::field($sent, 'task', 'id');
        self::assertNotSame('', $task);
        $kept = [];
        foreach ($this->sandbox->files as $files) {
            $kept = [...$kept, ...array_keys($files)];
        }
        self::assertSame(['inbox/' . $task . '/qr.png', 'inbox/' . $task . '/_.._etc_passwd'], $kept, 'a name is a name, not a path');
        $socket->close();
    }
    public function testAHandshakeRelayedThroughAnotherInstanceDoesNotOpen(): void {
        // M publishes the follower's key in its card; the leader dials M, and M passes every frame to the follower
        [$leaderEnd, $relayFromLeader] = InProcessA2aChannel::pair();
        [$relayToFollower] = $this->connection();
        $pump = static function (InProcessA2aChannel $from, InProcessA2aChannel $to): void {
            while (($frame = $from->receive()) !== null) {
                try {
                    $to->send($frame);
                } catch (RuntimeException) {
                    return;
                }
            }
            $to->close();
        };
        async(static fn() => $pump($relayFromLeader, $relayToFollower))->ignore();
        async(static fn() => $pump($relayToFollower, $relayFromLeader))->ignore();

        // spec: brief/network.md#подпись-адреса-доказательство-ключа-и-маскирование — the handshake names the address the leader dialed
        try {
            A2aSocket::open($leaderEnd, $this->leaderKey, $this->followerKey->publicKey, audience: 'https://m.example');
            self::fail('a relayed handshake opened a connection');
        } catch (RuntimeException $e) {
            self::assertNotSame('', $e->getMessage());
        }
        delay(0.05);
        self::assertSame([], array_filter(
            $relayToFollower->sent,
            static fn(string $f): bool => str_contains($f, '"SendMessage"'),
        ), 'nothing was sent as the leader');
    }

    public function testAnUnknownKeyIsDeniedWithItsFingerprint(): void {
        [$end, $follower] = $this->connection();
        $stranger = AgentIdentity::generate();

        try {
            A2aSocket::open($end, $stranger, $this->followerKey->publicKey, audience: self::FOLLOWER);
            self::fail('a stranger got in');
        } catch (RuntimeException $e) {
            self::assertStringContainsString($stranger->fingerprint(), $e->getMessage(), 'its owner can let it in by this fingerprint');
        }
        delay(0.01);
        self::assertTrue($follower->isClosed());
    }

    public function testAnUnknownAgentKnocksAndTheOwnerLetsItIn(): void {
        [$end] = $this->connection();
        $stranger = AgentIdentity::generate();
        try {
            A2aSocket::open($end, $stranger, $this->followerKey->publicKey, about: ['name' => 'claude-tester', 'url' => 'https://tester.example'], audience: self::FOLLOWER);
            self::fail('a stranger got in');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('waits for the decision', $e->getMessage());
        }
        $card = array_values(array_filter($this->ownerChat->texts('send'), static fn(string $t): bool => str_contains($t, 'claude-tester')));
        self::assertCount(1, $card, 'the owner sees who knocks');
        self::assertStringContainsString('https://tester.example', $card[0]);
        self::assertStringContainsString($stranger->fingerprint(), $card[0]);

        $this->requests->handleCallback(new \Mercurius\Messenger\IncomingCallback('telegram', 'cb', '42', '42', 'Owner', null, 'pa:1:u', 'u1'));

        [$again] = $this->connection();
        $socket = A2aSocket::open($again, $stranger, $this->followerKey->publicKey, audience: self::FOLLOWER);
        self::assertTrue($socket->alive());
        $socket->close();
    }

    public function testAnUnknownKeyOverHttpKnocksWithTheNameFromItsHeaders(): void {
        $stranger = AgentIdentity::generate();
        $nonce = bin2hex(random_bytes(8));
        $time = (string) time();
        [$agent, $status, $why] = $this->server->authenticate([
            'authorization' => 'Bearer entrance',
            'x-a2a-key' => base64_encode($stranger->publicKey),
            'x-a2a-timestamp' => $time,
            'x-a2a-nonce' => $nonce,
            'x-a2a-signature' => $stranger->sign(AgentIdentity::requestData('POST', '/a2a', $time, $nonce, '{}', self::FOLLOWER)),
            'x-a2a-name' => 'Http Agent',
            'x-a2a-url' => 'https://http-agent.example',
        ], 'POST', '/a2a', '{}');

        self::assertSame([null, 403], [$agent, $status]);
        self::assertStringContainsString('waits for the decision', $why);
        self::assertCount(1, array_filter($this->ownerChat->texts('send'), static fn(string $t): bool => str_contains($t, 'http-agent') && str_contains($t, 'HTTP')));
    }

    public function testAForeignSignatureIsNotAProof(): void {
        [$end, $follower] = $this->connection();
        $end->send((string) json_encode(['type' => 'hello', 'version' => A2aSocket::VERSION, 'key' => $this->leaderKey->publicKey, 'nonce' => str_repeat('a', 32)]));
        /** @var mixed $challenge */
        $challenge = self::decoded($end->receive());
        self::assertSame('challenge', self::field($challenge, 'type'));

        $forged = AgentIdentity::generate()->sign(AgentIdentity::wsClientData(self::field($challenge, 'nonce'), str_repeat('a', 32), $this->followerKey->fingerprint(), self::FOLLOWER));
        $end->send((string) json_encode(['type' => 'auth', 'signature' => $forged]));

        self::assertSame('denied', self::field(self::decoded($end->receive()), 'type'));
        delay(0.01);
        self::assertTrue($follower->isClosed());
    }

    public function testAHandshakeOfAnOlderVersionIsDeniedWithItsReason(): void {
        [$end, $follower] = $this->connection();
        $end->send((string) json_encode(['type' => 'hello', 'key' => $this->leaderKey->publicKey, 'nonce' => str_repeat('a', 32)]));

        // spec: brief/network.md#подпись-адреса-доказательство-ключа-и-маскирование — the handshake changed; an agent of an older version is told so
        /** @var mixed $denied */
        $denied = self::decoded($end->receive());
        self::assertSame('denied', self::field($denied, 'type'));
        self::assertStringContainsString('version', self::field($denied, 'reason'));
        delay(0.01);
        self::assertTrue($follower->isClosed());
    }

    public function testARecordedHandshakeCannotBeReplayed(): void {
        [$first] = $this->connection();
        A2aSocket::open($first, $this->leaderKey, $this->followerKey->publicKey, audience: self::FOLLOWER)->close();
        $hello = $first->sent[0] ?? '';
        $auth = $first->sent[1] ?? '';

        [$second] = $this->connection();
        $second->send($hello);
        $second->receive();
        $second->send($auth);

        self::assertSame('denied', self::field(self::decoded($second->receive()), 'type'), 'the follower\'s number is new on every connection');
    }

    public function testSomeoneElseAtTheNeighboursAddressIsNotTrusted(): void {
        [$end] = $this->connection();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('не ключом этого соседа');

        A2aSocket::open($end, $this->leaderKey, AgentIdentity::generate()->publicKey, audience: self::FOLLOWER);
    }

    public function testSilenceDuringTheHandshakeClosesTheConnection(): void {
        [, $follower] = $this->connection(0.1);

        delay(0.3);

        self::assertTrue($follower->isClosed());
    }
    /** A string deep in decoded JSON; '' when the path is not there. */
    private static function field(mixed $data, string ...$path): string {
        foreach ($path as $key) {
            if (!is_array($data) || !array_key_exists($key, $data)) {
                return '';
            }
            /** @var mixed $data */
            $data = $data[$key];
        }
        return is_string($data) ? $data : '';
    }

    private static function decoded(?string $json): mixed {
        return $json === null ? null : json_decode($json, true);
    }
}
