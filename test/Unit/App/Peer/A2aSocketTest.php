<?php

declare(strict_types=1);

namespace OpenCCK\Test\Unit\App\Peer;

use Amp\PHPUnit\AsyncTestCase;
use OpenCCK\App\Peer\A2aServer;
use OpenCCK\App\Peer\A2aSocket;
use OpenCCK\App\Peer\AgentIdentity;
use OpenCCK\App\Peer\PeerNetwork;
use OpenCCK\App\Vault\Vault;
use OpenCCK\Domain\Entity\Principal;
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
    private A2aServer $server;
    private AgentIdentity $leaderKey;
    private AgentIdentity $followerKey;

    protected function setUp(): void {
        parent::setUp();
        $this->leaderKey = AgentIdentity::generate();
        $this->followerKey = AgentIdentity::generate();
        $follower = new AgentStack(new ScriptedLlm([ScriptedLlm::text('Париж.')]));
        $follower->principals->ensureOwner('telegram', '42', 'Owner');
        $peers = new InMemoryPeerRepository();
        $this->server = new A2aServer($peers, $follower->principals, $follower->loop, $follower->runs, $follower->transcript, $follower->conversations, $follower->policies, 'test/model', 'https://follower.example', 'Follower', 'test', new NullLogger(), $this->followerKey, 'entrance');
        $follower->scheduler->useJobRuns($this->server);
        $network = new PeerNetwork($peers, $follower->principals, new Vault(new InMemorySecretRepository(), random_bytes(32)), new \OpenCCK\App\Peer\A2aClient(new \Amp\Http\Client\HttpClient(new \OpenCCK\Test\Support\FakeHttpClient(), []), $this->followerKey), $follower->conversations, $follower->transcript, $follower->channel, 'https://follower.example', new NullLogger(), $this->followerKey, static fn(): ?Principal => null);
        $network->allow('leader', $this->leaderKey->fingerprint(), Role::User);
    }

    /** @return array{InProcessA2aChannel, InProcessA2aChannel} the leader's end and the follower's end, served */
    private function connection(float $timeout = 5.0): array {
        [$leader, $follower] = InProcessA2aChannel::pair();
        async(fn() => $this->server->serveSocket($follower, $timeout))->ignore();
        return [$leader, $follower];
    }

    public function testAKnownAgentProvesItsKeyAndHandsOverATask(): void {
        [$end] = $this->connection();

        $socket = A2aSocket::open($end, $this->leaderKey, $this->followerKey->publicKey);
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
        self::assertSame(['hello', 'auth'], array_slice(array_map(static fn(string $f): string => self::field(self::decoded($f), 'type'), $end->sent), 0, 2));
        $socket->close();
    }

    public function testAnUnknownKeyIsDeniedWithItsFingerprint(): void {
        [$end, $follower] = $this->connection();
        $stranger = AgentIdentity::generate();

        try {
            A2aSocket::open($end, $stranger, $this->followerKey->publicKey);
            self::fail('a stranger got in');
        } catch (RuntimeException $e) {
            self::assertStringContainsString($stranger->fingerprint(), $e->getMessage(), 'its owner can let it in by this fingerprint');
        }
        delay(0.01);
        self::assertTrue($follower->isClosed());
    }

    public function testAForeignSignatureIsNotAProof(): void {
        [$end, $follower] = $this->connection();
        $end->send((string) json_encode(['type' => 'hello', 'key' => $this->leaderKey->publicKey, 'nonce' => str_repeat('a', 32)]));
        /** @var mixed $challenge */
        $challenge = self::decoded($end->receive());
        self::assertSame('challenge', self::field($challenge, 'type'));

        $forged = AgentIdentity::generate()->sign(AgentIdentity::wsClientData(self::field($challenge, 'nonce'), str_repeat('a', 32), $this->followerKey->fingerprint()));
        $end->send((string) json_encode(['type' => 'auth', 'signature' => $forged]));

        self::assertSame('denied', self::field(self::decoded($end->receive()), 'type'));
        delay(0.01);
        self::assertTrue($follower->isClosed());
    }

    public function testARecordedHandshakeCannotBeReplayed(): void {
        [$first] = $this->connection();
        A2aSocket::open($first, $this->leaderKey, $this->followerKey->publicKey)->close();
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

        A2aSocket::open($end, $this->leaderKey, AgentIdentity::generate()->publicKey);
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
