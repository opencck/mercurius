<?php

declare(strict_types=1);

namespace OpenCCK\Test\Unit\App\Peer;

use Amp\NullCancellation;
use Amp\PHPUnit\AsyncTestCase;
use Amp\Http\Client\HttpClient;
use OpenCCK\App\Agent\Tool\PeerDelegateTool;
use OpenCCK\App\Agent\Tool\ToolContext;
use OpenCCK\App\Peer\A2aClient;
use OpenCCK\App\Peer\A2aServer;
use OpenCCK\App\Peer\AgentIdentity;
use OpenCCK\App\Peer\PeerNetwork;
use OpenCCK\App\Vault\Vault;
use OpenCCK\Domain\Entity\Principal;
use OpenCCK\Domain\Entity\PrincipalKind;
use OpenCCK\Domain\Entity\Role;
use OpenCCK\Domain\Peer\A2aTask;
use OpenCCK\Test\Support\A2aWire;
use OpenCCK\Test\Support\AgentStack;
use OpenCCK\Test\Support\InMemoryPeerRepository;
use OpenCCK\Test\Support\InMemorySecretRepository;
use OpenCCK\Test\Support\ScriptedLlm;
use Psr\Log\NullLogger;

use function Amp\delay;

/**
 * Two agents in one process: the leader's HTTP goes straight into the follower's server, signed both ways.
 */
final class AgentNetworkTest extends AsyncTestCase {
    private const FOLLOWER = 'https://follower.example';
    private const LEADER = 'https://leader.example';
    private const ENTRANCE = 'entrance-token-of-the-follower';

    private AgentStack $follower;
    private InMemoryPeerRepository $followerPeers;
    private A2aServer $server;
    private AgentIdentity $leaderKey;
    private AgentIdentity $followerKey;

    private function follower(ScriptedLlm $llm): void {
        $this->leaderKey = AgentIdentity::generate();
        $this->followerKey = AgentIdentity::generate();
        $this->follower = new AgentStack($llm);
        $this->follower->principals->ensureOwner('telegram', '42', 'Owner of follower');
        $this->followerPeers = new InMemoryPeerRepository();
        $this->server = new A2aServer(
            $this->followerPeers,
            $this->follower->principals,
            $this->follower->loop,
            $this->follower->runs,
            $this->follower->transcript,
            $this->follower->conversations,
            $this->follower->policies,
            'test/model',
            self::FOLLOWER,
            'Follower',
            'test',
            new NullLogger(),
            $this->followerKey,
            self::ENTRANCE,
            files: new \OpenCCK\App\Peer\A2aFiles(new \OpenCCK\Test\Support\InMemoryA2aFileRepository()),
        );
        $this->follower->scheduler->useJobRuns($this->server);
        $network = $this->network($this->followerPeers, $this->follower, $this->followerKey, self::FOLLOWER, null);
        self::assertStringContainsString('может подключаться', $network->allow('leader', $this->leaderKey->fingerprint(), Role::User));
    }

    private function network(InMemoryPeerRepository $peers, AgentStack $stack, AgentIdentity $key, string $self, ?Principal $owner, ?Vault $vault = null): PeerNetwork {
        return new PeerNetwork(
            $peers,
            $stack->principals,
            $vault ?? new Vault(new InMemorySecretRepository(), random_bytes(32)),
            new A2aClient(new HttpClient(new A2aWire($this->server), []), $key),
            $stack->conversations,
            $stack->transcript,
            $stack->channel,
            $self,
            new NullLogger(),
            $key,
            static fn(): ?Principal => $owner,
        );
    }

    /**
     * A signed request as the leader sends it.
     *
     * @param array<string, mixed> $call
     * @return array{Principal|null, int, string}
     */
    private function authenticate(array $call, ?AgentIdentity $key = null, string $token = self::ENTRANCE, ?string $nonce = null, ?int $at = null, string $audience = self::FOLLOWER): array {
        $key ??= $this->leaderKey;
        $body = json_encode($call, JSON_THROW_ON_ERROR);
        $timestamp = (string) ($at ?? time());
        $nonce ??= bin2hex(random_bytes(16));
        return $this->server->authenticate([
            'authorization' => 'Bearer ' . $token,
            'x-a2a-key' => base64_encode($key->publicKey),
            'x-a2a-timestamp' => $timestamp,
            'x-a2a-nonce' => $nonce,
            'x-a2a-signature' => $key->sign(AgentIdentity::requestData('POST', '/a2a', $timestamp, $nonce, $body, $audience)),
        ], 'POST', '/a2a', $body);
    }

    private function leaderAgent(): Principal {
        [$agent] = $this->authenticate(['jsonrpc' => '2.0', 'id' => 0, 'method' => 'GetTask', 'params' => []]);
        self::assertNotNull($agent);
        return $agent;
    }

    /**
     * @param array<string, mixed> $chain
     * @return array<string, mixed>
     */
    private function send(string $messageId, string $text, array $chain = [], ?string $contextId = null): array {
        $message = ['messageId' => $messageId, 'parts' => [['text' => $text]]] + ($contextId === null ? [] : ['contextId' => $contextId]);
        [, $answer] = $this->server->handle($this->leaderAgent(), ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'SendMessage', 'params' => ['message' => $message, 'metadata' => ['mercurius' => $chain]]]);
        return $answer;
    }

    /** A value deep in a JSON answer; null when the path is not there. */
    private static function dig(mixed $data, string|int ...$path): mixed {
        foreach ($path as $key) {
            if (!is_array($data) || !array_key_exists($key, $data)) {
                return null;
            }
            /** @var mixed $data */
            $data = $data[$key];
        }
        return $data;
    }

    private static function text(mixed $data, string|int ...$path): string {
        /** @var mixed $value */
        $value = self::dig($data, ...$path);
        return is_string($value) ? $value : '';
    }

    public function testAgentsAreKnownByTheirKeysAndOnlyWithTheEntranceToken(): void {
        $this->follower(new ScriptedLlm([]));
        $call = ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'GetTask', 'params' => ['id' => 'x']];

        [$agent, $status] = $this->authenticate($call);
        self::assertSame(200, $status);
        self::assertSame([PrincipalKind::Agent, 'leader'], [$agent?->kind, $agent?->displayName]);

        self::assertSame(401, $this->authenticate($call, token: 'wrong')[1], 'the entrance needs the token from .env');
        [, $status, $why] = $this->authenticate($call, AgentIdentity::generate());
        self::assertSame(403, $status, 'a stranger key is not let in');
        self::assertStringContainsString('/peer allow', $why);
        self::assertSame(401, $this->authenticate($call, at: time() - A2aServer::CLOCK_SKEW - 5)[1], 'an old signature');
        $this->authenticate($call, nonce: 'nonce-used-once-000');
        self::assertSame(401, $this->authenticate($call, nonce: 'nonce-used-once-000')[1], 'a replay');

        $forged = $this->server->authenticate([
            'authorization' => 'Bearer ' . self::ENTRANCE,
            'x-a2a-key' => base64_encode($this->leaderKey->publicKey),
            'x-a2a-timestamp' => (string) time(),
            'x-a2a-nonce' => 'forged-signature-01',
            'x-a2a-signature' => AgentIdentity::generate()->sign('anything'),
        ], 'POST', '/a2a', '{}');
        self::assertSame(401, $forged[1], 'the leader key cannot be claimed without its private half');

        self::assertTrue($this->followerPeers->revokeKey('leader'));
        self::assertSame(403, $this->authenticate($call)[1], 'a revoked agent no longer connects');
    }

    public function testTheNumberOfATaskANeighbourGivesIsANumberNotAPath(): void {
        // spec: BRIEF.md#решения-по-доработкам-2026-11-18 — files of an answer stay in the inbox of their task
        self::assertSame('task-8cb1b5099f171751', A2aClient::taskId(['id' => 'task-8cb1b5099f171751']));
        foreach (['../scripts', 'a/b', '..', '', str_repeat('x', 101), "a\nb"] as $id) {
            self::assertNull(A2aClient::taskId(['id' => $id]), $id);
        }
        self::assertNull(A2aClient::taskId(['id' => 42]));
        $out = new \OpenCCK\Domain\Peer\OutgoingTask(7, 'doorotar', '../x', 1, 'telegram', '1', 't', A2aTask::WORKING, null);
        self::assertSame('inbox/doorotar-7', PeerNetwork::inboxOf($out), 'the path comes from our own number of the task');
    }

    public function testARequestSignedForAnotherInstanceIsRefused(): void {
        $this->follower(new ScriptedLlm([]));

        // spec: BRIEF.md#решения-по-доработкам-2026-11-18 — a signed request names the instance it is for
        [$agent, $status] = $this->authenticate(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'GetTask', 'params' => []], audience: 'https://other.example');
        self::assertNull($agent);
        self::assertSame(401, $status);
        [$agent] = $this->authenticate(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'GetTask', 'params' => []], audience: self::FOLLOWER . '/');
        self::assertNotNull($agent, 'the same address in another spelling is the same instance');
    }

    public function testANeighbourIsAddedOnlyWhenItsCardProvesTheKey(): void {
        $this->follower(new ScriptedLlm([]));
        $leader = new AgentStack(new ScriptedLlm([]));
        $owner = $leader->principals->ensureOwner('telegram', '7', 'Owner of leader');
        $vault = new Vault(new InMemorySecretRepository(), random_bytes(32));
        $vault->put('m-entrance', 'token', self::ENTRANCE, $owner);
        // M answers at its own address with the card of the follower: the key of someone else, asked on M's behalf
        $server = $this->server;
        $relay = new class($server) implements \Amp\Http\Client\DelegateHttpClient {
            public function __construct(private readonly A2aServer $server) {}

            public function request(\Amp\Http\Client\Request $request, \Amp\Cancellation $cancellation): \Amp\Http\Client\Response {
                parse_str($request->getUri()->getQuery(), $query);
                $nonce = isset($query['nonce']) && is_string($query['nonce']) ? $query['nonce'] : null;
                return new \Amp\Http\Client\Response('1.1', 200, null, [], json_encode($this->server->card($nonce), JSON_THROW_ON_ERROR), $request);
            }
        };
        $network = new PeerNetwork(new InMemoryPeerRepository(), $leader->principals, $vault, new A2aClient(new HttpClient($relay, []), $this->leaderKey), $leader->conversations, $leader->transcript, $leader->channel, self::LEADER, new NullLogger(), $this->leaderKey, static fn(): Principal => $owner);

        try {
            $network->add($owner, 'm', 'https://m.example', 'm-entrance');
            self::fail('a card with a key proven for another address was taken');
        } catch (\DomainException $e) {
            self::assertStringContainsString('ключ', $e->getMessage());
        }
    }

    public function testATaskOfANeighbourRunsUnderItsOwnAgentAndAnswersWithoutSecrets(): void {
        $this->follower(new ScriptedLlm([ScriptedLlm::text('Готово. Ключ sk-or-v1-0123456789abcdef0123456789abcdef использовать нельзя.')]));

        $answer = $this->send('m1', 'Проверь, сколько будет 2+2');
        self::assertSame(A2aTask::WORKING, self::dig($answer, 'result', 'task', 'status', 'state'));
        $taskId = self::text($answer, 'result', 'task', 'id');
        delay(0.05);

        [, $got] = $this->server->handle($this->leaderAgent(), ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tasks/get', 'params' => ['id' => $taskId]]);
        self::assertSame(A2aTask::COMPLETED, self::dig($got, 'result', 'status', 'state'));
        $text = self::text($got, 'result', 'status', 'message', 'parts', 0, 'text');
        self::assertStringStartsWith('Готово.', $text);
        self::assertStringNotContainsString('0123456789abcdef', $text, 'the answer leaves without secrets');
        $prompt = array_slice($this->follower->llm->requests[0]->messages, -1)[0]->content ?? '';
        self::assertStringContainsString('Задача от агента «leader»', $prompt);
        self::assertSame([], $this->follower->llm->requests[0]->tools, 'an agent starts with no tools');

        self::assertSame($taskId, self::dig($this->send('m1', 'Проверь, сколько будет 2+2'), 'result', 'task', 'id'), 'the same messageId is the same task');
        self::assertCount(1, $this->follower->llm->requests);
    }

    public function testLoopsDepthAndPingPongAreRefused(): void {
        $this->follower(new ScriptedLlm([]));

        $loop = $this->send('m1', 'x', ['depth' => 1, 'path' => [self::LEADER, self::FOLLOWER]]);
        self::assertSame(A2aTask::REJECTED, self::dig($loop, 'result', 'task', 'status', 'state'));
        self::assertStringContainsString('Цикл', self::text($loop, 'result', 'task', 'status', 'message', 'parts', 0, 'text'));

        $deep = $this->send('m2', 'x', ['depth' => A2aServer::MAX_DEPTH, 'path' => ['a', 'b', 'c']]);
        self::assertSame(A2aTask::REJECTED, self::dig($deep, 'result', 'task', 'status', 'state'));

        $leader = $this->leaderAgent();
        for ($i = 0; $i < A2aServer::MAX_TURNS; $i++) {
            $this->followerPeers->createTask('t' . $i, $leader->id, 'ctx-chat', 'old' . $i, A2aTask::COMPLETED);
        }
        self::assertStringContainsString('не больше 5', self::text($this->send('m3', 'x', [], 'ctx-chat'), 'result', 'task', 'status', 'message', 'parts', 0, 'text'));

        [, $unknown] = $this->server->handle($leader, ['jsonrpc' => '2.0', 'id' => 9, 'method' => 'tasks/resubscribe', 'params' => []]);
        self::assertSame(A2aServer::ERR_METHOD_NOT_FOUND, self::dig($unknown, 'error', 'code'));
        [, $bad] = $this->server->handle($leader, ['jsonrpc' => '2.0', 'id' => 9, 'method' => 'SendMessage', 'params' => ['message' => ['parts' => []]]]);
        self::assertSame(A2aServer::ERR_INVALID_PARAMS, self::dig($bad, 'error', 'code'));
    }

    public function testTheRateIsCappedPerAgent(): void {
        $this->follower(new ScriptedLlm([]));
        $leader = $this->leaderAgent();
        for ($i = 0; $i < A2aServer::RATE; $i++) {
            $this->server->handle($leader, ['jsonrpc' => '2.0', 'id' => $i, 'method' => 'GetTask', 'params' => ['id' => 'none']]);
        }
        [$status] = $this->server->handle($leader, ['jsonrpc' => '2.0', 'id' => 99, 'method' => 'GetTask', 'params' => ['id' => 'none']]);
        self::assertSame(429, $status);
    }

    public function testTheLeaderDelegatesChecksWhoAnsweredAndGetsTheAnswerAsData(): void {
        $this->follower(new ScriptedLlm([ScriptedLlm::text('Столица Франции — Париж.')]));
        $leader = new AgentStack(new ScriptedLlm([]));
        $owner = $leader->principals->ensureOwner('telegram', '7', 'Owner of leader');
        $vault = new Vault(new InMemorySecretRepository(), random_bytes(32));
        $vault->put('follower-entrance', 'token', self::ENTRANCE, $owner);
        $leaderPeers = new InMemoryPeerRepository();
        $network = $this->network($leaderPeers, $leader, $this->leaderKey, self::LEADER, $owner, $vault);
        $ended = [];
        $network->onFinished(static function (\OpenCCK\Domain\Peer\OutgoingTask $out) use (&$ended): void {
            $ended[] = $out;
        });

        $added = $network->add($owner, 'follower', self::FOLLOWER, 'follower-entrance');
        self::assertStringContainsString($this->followerKey->fingerprint(), $added, 'the owner sees the key to compare');
        self::assertStringContainsString('/peer allow <имя для меня> ' . $this->leaderKey->fingerprint(), $added);

        $leader->say('привет', '7');
        $conversation = $leader->conversations->openConversation($owner->id, 'telegram', '7');
        $tool = new PeerDelegateTool($network, $leaderPeers, $leader->conversations);
        $result = $tool->execute(['peer' => 'follower', 'task' => 'Какая столица Франции?'], new ToolContext(1, $conversation, $owner, new NullCancellation()));
        self::assertFalse($result->isError, $result->content);
        self::assertStringContainsString('Задача #1 отдана соседу «follower»', $result->content);

        delay(0.05);
        $network->poll();

        $sent = implode("\n", $leader->channel->texts('send'));
        self::assertStringContainsString('📨 Агент «follower», задача #1', $sent);
        self::assertStringContainsString('Париж', $sent);
        self::assertSame(A2aTask::COMPLETED, $leaderPeers->outgoing[1]->state ?? null);
        self::assertCount(1, $ended, 'the server of the leader learns the task ended');
        self::assertSame(1, $ended[0]->leaderRun);
        $first = array_key_first($this->followerPeers->tasks);
        self::assertNotNull($first);
        self::assertSame([self::LEADER, self::FOLLOWER], $this->followerPeers->tasks[$first]->path, 'the chain carries both instances');

        // Someone else at the follower's address: answers signed by another key are refused.
        $leaderPeers->addPeer('follower', self::FOLLOWER, 'follower-entrance', AgentIdentity::generate()->publicKey, null);
        $impostor = $tool->execute(['peer' => 'follower', 'task' => 'ещё вопрос'], new ToolContext(1, $conversation, $owner, new NullCancellation()));
        self::assertTrue($impostor->isError);
        self::assertStringContainsString('не ключом этого соседа', $impostor->content);
    }

    public function testATaskThatDelegatedFurtherWaitsForTheAnswerAndThenSumsUp(): void {
        $llm = new ScriptedLlm([ScriptedLlm::text('Спросил соседа C, жду ответа.'), ScriptedLlm::text('Итог: по данным C — Париж.')]);
        $llm->delay = 0.02;
        $this->follower($llm);
        $answer = $this->send('msg-chain', 'Какая столица Франции? Узнай у C.', [], 'ctx-chain');
        $taskId = self::text($answer, 'result', 'task', 'id');
        $task = $this->followerPeers->task($taskId);
        self::assertNotNull($task);
        self::assertNotNull($task->runId);
        // The run gives a task to C while it works: the task of the leader waits for C.
        $chat = 'a2a-' . $task->principalId . '-ctx-chain';
        $out = $this->followerPeers->addOutgoing('c', 'remote-1', $task->principalId, A2aServer::CHANNEL, $chat, 'столица Франции', A2aTask::WORKING, $task->runId);
        delay(0.1);
        self::assertSame(A2aTask::WORKING, $this->followerPeers->task($taskId)?->state, 'the run ended, but C has not answered yet');

        // C answers: the reply is in the conversation, a new run sums up and the leader's task is completed with it.
        $this->followerPeers->finishOutgoing($out->id, A2aTask::COMPLETED, 'Париж');
        $conversation = $this->follower->conversations->openConversation($task->principalId, A2aServer::CHANNEL, $chat);
        $this->follower->transcript->appendUser($conversation, null, 'Итог задачи #1 соседу «c»: Париж');
        $this->server->chainAnswered($this->followerPeers->outgoing[$out->id]);
        delay(0.1);
        $done = $this->followerPeers->tasks[$taskId] ?? self::fail('the task is gone');
        self::assertSame(A2aTask::COMPLETED, $done->state);
        self::assertSame('Итог: по данным C — Париж.', $done->result);
        $last = end($llm->requests);
        self::assertNotFalse($last);
        self::assertStringContainsString('Париж', json_encode($last->messages, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), 'the summing run sees the answer of C');

        // An answer of C for a task that already ended changes nothing.
        $this->server->chainAnswered($this->followerPeers->outgoing[$out->id]);
        delay(0.05);
        self::assertSame('Итог: по данным C — Париж.', $this->followerPeers->task($taskId)?->result);
    }

    public function testATaskOfATaskCarriesEachInstanceOnceInItsPath(): void {
        $this->follower(new ScriptedLlm([ScriptedLlm::text('ок')]));
        $leader = new AgentStack(new ScriptedLlm([]));
        $owner = $leader->principals->ensureOwner('telegram', '7', 'Owner of leader');
        $vault = new Vault(new InMemorySecretRepository(), random_bytes(32));
        $vault->put('follower-entrance', 'token', self::ENTRANCE, $owner);
        $network = $this->network(new InMemoryPeerRepository(), $leader, $this->leaderKey, self::LEADER, $owner, $vault);
        $network->add($owner, 'follower', self::FOLLOWER, 'follower-entrance');

        // the run is a task that came from someone else: its stored path already ends with this instance
        $network->delegate($owner, 'follower', 'Сделай', A2aServer::CHANNEL, 'a2a-1-ctx', 1, ['https://first.example', self::LEADER]);

        $first = array_key_first($this->followerPeers->tasks);
        self::assertNotNull($first);
        self::assertSame(['https://first.example', self::LEADER, self::FOLLOWER], $this->followerPeers->tasks[$first]->path);
        self::assertSame(2, $this->followerPeers->tasks[$first]->depth);
    }

    public function testTheLeadersTraceShowsTheRemoteRunAndItsSpend(): void {
        $this->follower(new ScriptedLlm([ScriptedLlm::text('Готово.', 1200, 300)]));
        $leader = new AgentStack(new ScriptedLlm([]));
        $owner = $leader->principals->ensureOwner('telegram', '7', 'Owner of leader');
        $vault = new Vault(new InMemorySecretRepository(), random_bytes(32));
        $vault->put('follower-entrance', 'token', self::ENTRANCE, $owner);
        $leaderPeers = new InMemoryPeerRepository();
        $network = $this->network($leaderPeers, $leader, $this->leaderKey, self::LEADER, $owner, $vault);
        $network->add($owner, 'follower', self::FOLLOWER, 'follower-entrance');
        $conversation = $leader->conversations->openConversation($owner->id, 'telegram', '7');

        $result = (new PeerDelegateTool($network, $leaderPeers, $leader->conversations))->execute(['peer' => 'follower', 'task' => 'Сделай'], new ToolContext(41, $conversation, $owner, new NullCancellation()));
        self::assertFalse($result->isError, $result->content);
        delay(0.05);
        $network->poll();

        $spent = $leaderPeers->outgoingOfRun(41);
        self::assertCount(1, $spent, 'the task is known by the leader\'s run');
        self::assertNotNull($spent[0]['run'], 'the number of the remote run came back');
        self::assertSame(1500, $spent[0]['tokens'], 'the follower\'s tokens');

        $trace = new \OpenCCK\Test\Support\InMemoryTraceRepository();
        $trace->spans[] = new \OpenCCK\Domain\Agent\TraceSpan(41, null, \OpenCCK\Domain\Agent\TraceSpan::RUN, 'agent', new \DateTimeImmutable(), 10, 'ok', costUsd: 0.01);
        $text = (string) (new \OpenCCK\App\Service\TraceReports(new \OpenCCK\Test\Support\FakeTraceReports(), $trace, $leaderPeers))->trace(41);
        self::assertStringContainsString('follower', $text);
        self::assertStringContainsString('#' . $spent[0]['run'], $text, 'the remote run number');
        self::assertStringContainsString('1500', $text);
    }

    public function testFilesGoAheadOfTheTaskAndComeBackWithTheAnswer(): void {
        $this->follower(new ScriptedLlm([ScriptedLlm::text('Отчёт готов, файл приложен.')]));
        $leader = new AgentStack(new ScriptedLlm([]));
        $owner = $leader->principals->ensureOwner('telegram', '7', 'Owner of leader');
        $vault = new Vault(new InMemorySecretRepository(), random_bytes(32));
        $vault->put('follower-entrance', 'token', self::ENTRANCE, $owner);
        $network = $this->network(new InMemoryPeerRepository(), $leader, $this->leaderKey, self::LEADER, $owner, $vault);
        $network->add($owner, 'follower', self::FOLLOWER, 'follower-entrance');

        $sandbox = new \OpenCCK\Test\Support\FakeSandbox();
        $sandbox->files[$owner->id]['tables/data.csv'] = "a;b\n1;2";
        $conversation = $leader->conversations->openConversation($owner->id, 'telegram', '7');
        $tool = new PeerDelegateTool($network, new InMemoryPeerRepository(), $leader->conversations, $sandbox);
        $policy = new \OpenCCK\Domain\Agent\ParticipantPolicy(null, new \OpenCCK\Domain\Agent\RunLimits(30, 1000, 900, 120), new \OpenCCK\Domain\Agent\SandboxLimits(100, 10, 5, 1000, 1), true);
        $lost = $tool->execute(['task' => 'Сделай отчёт', 'files' => ['tables/data.csv']], new ToolContext(1, $conversation, $owner, new NullCancellation(), $policy));
        self::assertTrue($lost->isError, 'a task without a neighbour is an error, not the list of neighbours');
        $given = $tool->execute(['peer' => 'follower', 'task' => 'Сделай отчёт по таблице', 'files' => ['tables/data.csv']], new ToolContext(1, $conversation, $owner, new NullCancellation(), $policy));
        self::assertFalse($given->isError, $given->content);
        self::assertStringContainsString('data.csv', $given->content);
        delay(0.05);

        $prompt = array_slice($this->follower->llm->requests[0]->messages, -1)[0]->content ?? '';
        self::assertStringContainsString('data.csv', $prompt, 'the file came with the task, sent ahead');
        $task = array_values($this->followerPeers->tasks)[0];
        self::assertNotNull($task->runId);
        self::assertTrue($this->server->answerFile($task->runId, 'report.pdf', 'PDF-BYTES'));

        $network->poll();

        self::assertStringContainsString('Отчёт готов', implode("
", $leader->channel->texts('send')));
        self::assertSame([['7', 'report.pdf', 'PDF-BYTES']], array_map(static fn(array $f): array => array_slice($f, 0, 3), $leader->channel->files), 'the file of the answer comes to the chat');
    }
}
