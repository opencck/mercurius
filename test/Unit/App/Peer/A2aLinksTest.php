<?php

declare(strict_types=1);

namespace OpenCCK\Test\Unit\App\Peer;

use Amp\Http\Client\HttpClient;
use Amp\PHPUnit\AsyncTestCase;
use Mercurius\Peer\A2aClient;
use Mercurius\Peer\A2aLinks;
use OpenCCK\App\Peer\A2aServer;
use Mercurius\Peer\AgentIdentity;
use OpenCCK\App\Peer\PeerNetwork;
use OpenCCK\App\Vault\Vault;
use OpenCCK\Domain\Entity\Member;
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
    /** @var array<string, array{stack: AgentStack, server: A2aServer, network: PeerNetwork, links: A2aLinks, key: AgentIdentity, owner: Member, peers: InMemoryPeerRepository, requests: \OpenCCK\App\Peer\AgentRequests}> */
    private array $agents = [];
    public int $connects = 0;

    /** @param list<string> $script answers in turn instead of the same one */
    private function agent(string $name, string $answer, array $script = []): void {
        $stack = new AgentStack(new ScriptedLlm($script === [] ? array_fill(0, 6, ScriptedLlm::text($answer)) : array_map(static fn(string $t) => ScriptedLlm::text($t), $script)));
        $owner = $stack->principals->ensureOwner('telegram', $name, 'Owner ' . $name);
        $vault = new Vault(new InMemorySecretRepository(), random_bytes(32));
        $vault->put('entrance', 'token', 'entrance', $owner);
        $key = AgentIdentity::generate();
        $peers = new InMemoryPeerRepository();
        $links = new A2aLinks();
        $url = 'https://' . $name . '.example';
        $requests = new \OpenCCK\App\Peer\AgentRequests($peers, $stack->principals, $stack->channel, $name, retryDelays: []);
        $server = new A2aServer($peers, $stack->principals, $stack->loop, $stack->runs, $stack->transcript, $stack->conversations, $stack->policies, 'test/model', $url, $name, 'test', new NullLogger(), $key, 'entrance', transport: 'ws', links: $links, requests: $requests);
        $stack->scheduler->useJobRuns($server);
        $test = $this;
        $connect = function (string $wsUrl, string $token) use ($test): InProcessA2aChannel {
            $test->connects++;
            if (parse_url($wsUrl, PHP_URL_HOST) === 'c.example') {
                throw new \RuntimeException('Сосед не ответил (down).');
            }
            $target = $test->agents[parse_url($wsUrl, PHP_URL_HOST) === 'b.example' ? 'b' : 'a']['server'];
            [$mine, $theirs] = InProcessA2aChannel::pair();
            async(static fn() => $target->serveSocket($theirs))->ignore();
            return $mine;
        };
        $client = new A2aClient(new HttpClient(new A2aWire($server), []), $key, null, $connect, $links, $server->handlerFor(...));
        $network = new PeerNetwork($peers, $stack->principals, $vault, $client, $stack->conversations, $stack->transcript, $stack->channel, $url, new NullLogger(), $key, static fn(): Member => $owner, null, $links);
        $this->agents[$name] = ['stack' => $stack, 'server' => $server, 'network' => $network, 'links' => $links, 'key' => $key, 'owner' => $owner, 'peers' => $peers, 'requests' => $requests];
    }

    /** Read anew each time: a connection is counted inside the event loop. */
    private function connects(): int {
        return $this->connects;
    }

    protected function setUp(): void {
        parent::setUp();
        $this->agent('a', 'A: готово');
        $this->agent('b', 'B: четыре');
        $this->connectAgents();
    }

    private function connectAgents(): void {
        // each owner lets the other agent in; only a knows b as a neighbour to connect to
        $this->agents['b']['network']->allow('agent-a', $this->agents['a']['key']->fingerprint(), Role::user());
        $this->agents['a']['network']->allow('agent-b', $this->agents['b']['key']->fingerprint(), Role::user());
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

    public function testTheNeighbourTellsOverTheConnectionThatTheTaskEnded(): void {
        $a = $this->agents['a'];
        $a['network']->open('b');

        $task = $a['network']->delegate($a['owner'], 'b', 'Посчитай', 'telegram', 'a', 0, []);
        // no polling: b pushes the end of the task over the connection, a reads the answer at once
        for ($i = 0; $i < 25 && ($a['peers']->outgoing[$task->id]->state ?? '') !== A2aTask::COMPLETED; $i++) {
            delay(0.02);
        }

        self::assertSame(A2aTask::COMPLETED, $a['peers']->outgoing[$task->id]->state ?? null);
        self::assertStringContainsString('B: четыре', implode("
", $a['stack']->channel->texts('send')));
    }

    public function testPeerCallWaitsForTheAnswerAndOrchestrateMergesTheParts(): void {
        // spec: brief/network.md#вызов-и-раздача-соседям — peer_call waits for one agent, peer_orchestrate gives parts to several and merges
        $a = $this->agents['a'];
        $a['network']->open('b');
        $conversation = $a['stack']->conversations->openConversation($a['owner']->id, 'telegram', 'a');
        $context = new \OpenCCK\App\Agent\Tool\ToolContext(1, $conversation, $a['owner'], new \Amp\NullCancellation());

        $called = (new \OpenCCK\App\Agent\Tool\Agents\PeerCallTool($a['network'], $a['peers'], $a['stack']->conversations))->execute(['peer' => 'b', 'task' => 'Посчитай', 'wait' => 5], $context);
        self::assertFalse($called->isError, $called->content);
        self::assertStringContainsString('B: четыре', $called->content, 'the answer comes back to the call');
        self::assertStringNotContainsString('B: четыре', implode("\n", $a['stack']->channel->texts('send')), 'not to the chat');

        $merged = (new \OpenCCK\App\Agent\Tool\Agents\PeerOrchestrateTool($a['network'], $a['peers'], $a['stack']->conversations))->execute(['parts' => [['peer' => 'b', 'task' => 'Часть один'], ['peer' => 'b', 'task' => 'Часть два']], 'wait' => 5], $context);
        self::assertFalse($merged->isError, $merged->content);
        self::assertSame(2, substr_count($merged->content, 'B: четыре'), 'both parts answered and merged');
        self::assertStringContainsString('1.', $merged->content);
        self::assertStringContainsString('2.', $merged->content);
    }

    public function testAQuestionOfTheNeighbourIsAnsweredInTheSameContext(): void {
        // spec: brief/network.md#вызов-и-раздача-соседям — the leader answers the question of the neighbour in its context
        $this->agent('b', '', ['[INPUT_REQUIRED] Какой город?', 'Погода в Москве: ясно.']);
        $this->connectAgents();
        $a = $this->agents['a'];
        $a['network']->open('b');
        $conversation = $a['stack']->conversations->openConversation($a['owner']->id, 'telegram', 'a');
        $context = new \OpenCCK\App\Agent\Tool\ToolContext(1, $conversation, $a['owner'], new \Amp\NullCancellation());
        $call = new \OpenCCK\App\Agent\Tool\Agents\PeerCallTool($a['network'], $a['peers'], $a['stack']->conversations);

        $question = $call->execute(['peer' => 'b', 'task' => 'Какая погода?', 'wait' => 5], $context);
        self::assertStringContainsString('Какой город?', $question->content);
        $asked = array_key_last($a['peers']->outgoing);
        self::assertNotNull($asked);
        self::assertNotSame('', $a['peers']->outgoing[$asked]->contextId, 'the context of the neighbour is kept');

        $answer = $call->execute(['peer' => 'b', 'task' => 'Москва', 'reply_to' => $asked, 'wait' => 5], $context);
        self::assertStringContainsString('Погода в Москве', $answer->content);
        $second = $this->agents['b']['stack']->llm->requests[1]->messages ?? [];
        self::assertStringContainsString('Какой город?', implode("\n", array_map(static fn($m): string => $m->content, $second)), 'the neighbour sees its question: the same context');
    }

    public function testTheLeaderCancelsATaskAndAnOldTaskEndsByItsDeadline(): void {
        // spec: brief/network.md#вызов-и-раздача-соседям — CancelTask by the leader; a task without an answer ends after two hours
        $a = $this->agents['a'];
        $a['network']->open('b');
        $conversation = $a['stack']->conversations->openConversation($a['owner']->id, 'telegram', 'a');
        $context = new \OpenCCK\App\Agent\Tool\ToolContext(1, $conversation, $a['owner'], new \Amp\NullCancellation());

        $task = $a['network']->delegate($a['owner'], 'b', 'Долгая работа', 'telegram', 'a', 0, []);
        $canceled = (new \OpenCCK\App\Agent\Tool\Agents\PeerCancelTool($a['network']))->execute(['task' => $task->id], $context);
        self::assertFalse($canceled->isError, $canceled->content);
        self::assertSame(A2aTask::CANCELED, $a['peers']->outgoing[$task->id]->state ?? null);

        $old = $a['peers']->addOutgoing('b', 'gone-task', $a['owner']->id, 'telegram', 'a', 'старая задача', A2aTask::WORKING);
        $a['peers']->outgoingAt[$old->id] = time() - 3 * 3600;
        $fresh = $a['network']->delegate($a['owner'], 'b', 'Свежая', 'telegram', 'a', 0, []);
        for ($i = 0; $i < 20 && ($a['peers']->outgoing[$fresh->id]->state ?? '') !== A2aTask::COMPLETED; $i++) {
            delay(0.02);
            $a['network']->poll();
        }
        self::assertSame(A2aTask::FAILED, $a['peers']->outgoing[$old->id]->state ?? null, 'past its deadline');
        self::assertSame(A2aTask::COMPLETED, $a['peers']->outgoing[$fresh->id]->state ?? null, 'the others are still read');
    }

    public function testTheNeighbourKeepsTheLeadersRunAndTakesItsTokenCap(): void {
        // spec: brief/network.md#вызов-и-раздача-соседям — the leader's run and its token cap go in metadata.mercurius
        $a = $this->agents['a'];
        $a['network']->open('b');
        $conversation = $a['stack']->conversations->openConversation($a['owner']->id, 'telegram', 'a');
        $policy = new \OpenCCK\Domain\Agent\ParticipantPolicy(null, new \OpenCCK\Domain\Agent\RunLimits(30, 777, 900, 120), null, true);
        $context = new \OpenCCK\App\Agent\Tool\ToolContext(7, $conversation, $a['owner'], new \Amp\NullCancellation(), $policy);

        (new \OpenCCK\App\Agent\Tool\Agents\PeerCallTool($a['network'], $a['peers'], $a['stack']->conversations))->execute(['peer' => 'b', 'task' => 'Посчитай', 'wait' => 5], $context);

        $b = $this->agents['b'];
        $task = end($b['peers']->tasks);
        self::assertNotFalse($task);
        self::assertSame(7, $task->leaderRun, 'the run of the leader is kept for the trace');
        self::assertNotNull($task->runId);
        $run = $b['stack']->runs->find($task->runId);
        self::assertNotNull($run);
        self::assertLessThanOrEqual(777, $run->limits->tokenBudget, 'the neighbour takes the smaller budget');
    }

    public function testANoticeOfATaskThisAgentDidNotGiveChangesNothing(): void {
        $a = $this->agents['a'];
        $a['network']->noticed('b', 'TaskStatusUpdate', ['id' => 'task-unknown', 'state' => A2aTask::COMPLETED]);
        $a['network']->noticed('b', 'Whatever', []);
        self::assertSame([], $a['stack']->channel->texts('send'));
    }

    public function testARevokedKeyStopsWorkingOnTheOpenConnectionAndTheConnectionCloses(): void {
        $a = $this->agents['a'];
        $b = $this->agents['b'];
        $a['network']->open('b');
        $a['network']->delegate($a['owner'], 'b', 'Посчитай', 'telegram', 'a', 0, []);

        // spec: brief/network.md#подпись-адреса-доказательство-ключа-и-маскирование — a revoked key does not work on a connection already open
        $b['peers']->revokeKey('agent-a');
        $refused = null;
        try {
            $a['network']->delegate($a['owner'], 'b', 'Ещё', 'telegram', 'a', 0, []);
        } catch (\RuntimeException|\DomainException $e) {
            $refused = $e->getMessage();
        }
        self::assertNotNull($refused, 'a revoked agent gave a task over its open connection');

        delay(0.02);
        self::assertSame([], $b['links']->all(), 'the connection of a revoked key is closed');

        $b['peers']->restoreKey($a['key']->fingerprint());
        $a['network']->open('b');
        self::assertNotSame([], $b['links']->all());
        self::assertTrue($b['network']->revoke('agent-a'));
        delay(0.02);
        self::assertSame([], $b['links']->all(), '/peer revoke closes the connections of that key');
    }

    public function testALiveConnectionIsUsedInsteadOfAnotherAndCanBeClosed(): void {
        $a = $this->agents['a'];
        $a['network']->open('b');
        $a['network']->delegate($a['owner'], 'b', 'Посчитай', 'telegram', 'a', 0, []);
        $a['network']->delegate($a['owner'], 'b', 'Ещё', 'telegram', 'a', 0, []);

        self::assertSame(1, $this->connects, 'one connection carries every task');
        delay(0.1); // the answers come back over the same connection before it is closed
        self::assertSame(1, $this->connects(), 'the answers came over it too');
        $all = $a['links']->all();
        $id = $all === [] ? 0 : $all[0]['id'];
        self::assertTrue($a['network']->closeLink($id));
        delay(0.02);
        self::assertSame([], $a['links']->all());
        self::assertSame([], $this->agents['b']['links']->all(), 'the other side sees it closed');
    }

    public function testAParticipantTheOwnerGaveTheToolOpensAConnectionToo(): void {
        $a = $this->agents['a'];
        $guest = $a['stack']->principals->createAgent('rekryt', Role::user());
        $tool = new \OpenCCK\App\Agent\Tool\Agents\PeerLinksTool($a['network']);
        $context = new \OpenCCK\App\Agent\Tool\ToolContext(1, 1, $guest, new \Amp\NullCancellation());

        $result = $tool->execute(['action' => 'open', 'peer' => 'b'], $context);

        self::assertFalse($result->isError, 'the policy decides who has the tool, not the tool itself: ' . $result->content);
        self::assertCount(1, $a['links']->all());
    }

    public function testAConnectionTheAgentOpenedIsKeptAndOpenedAgainWhenItDrops(): void {
        $a = $this->agents['a'];
        $a['network']->open('b');
        self::assertTrue($a['peers']->peer('b')?->keep, 'kept, also after a restart');

        foreach ($this->agents['b']['links']->all() as $link) {
            $this->agents['b']['links']->close($link['id']);
        }
        delay(0.02);
        self::assertSame([], $a['links']->all(), 'the other side dropped it');
        $a['network']->keepAlive();

        self::assertSame(2, $this->connects(), 'opened again');
        $live = $a['links']->all();
        self::assertSame([[A2aLinks::OUT, 'b']], array_map(static fn(array $l): array => [$l['direction'], $l['name']], $live));
        $a['network']->keepAlive();
        self::assertSame(array_column($live, 'id'), array_column($a['links']->all(), 'id'), 'a live connection is left alone');
    }

    public function testClosingAConnectionStopsKeepingIt(): void {
        $a = $this->agents['a'];
        $a['network']->open('b');
        $all = $a['links']->all();
        $id = $all === [] ? 0 : $all[0]['id'];

        self::assertTrue($a['network']->closeLink($id));
        delay(0.02);
        $a['network']->keepAlive();

        self::assertFalse($a['peers']->peer('b')?->keep);
        self::assertSame(1, $this->connects);
    }

    public function testAnUnreachableNeighbourIsTriedAgainLater(): void {
        $a = $this->agents['a'];
        $a['peers']->addPeer('c', 'https://c.example', 'entrance', $this->agents['b']['key']->publicKey, ['mercurius' => ['transport' => 'ws', 'ws' => 'wss://c.example/a2a/ws']]);
        $a['peers']->setKeep('c', true);

        $a['network']->keepAlive();
        $a['network']->keepAlive();

        self::assertSame(1, $this->connects, 'one try, the next after a pause');
        self::assertTrue($a['peers']->peer('c')?->keep, 'still kept: it may come back');
    }

    public function testANeighbourNotLetInKnocksOverMyConnectionAndIsLetInWithoutReconnecting(): void {
        $a = $this->agents['a'];
        $b = $this->agents['b'];
        // a connects to b, but a's owner never let b give tasks
        $a['peers']->revokeKey('agent-b');
        $a['network']->open('b');

        try {
            $b['network']->delegate($b['owner'], 'agent-a', 'Как дела?', 'telegram', 'b', 0, []);
            self::fail('a took a task from b without its owner');
        } catch (\DomainException $e) {
            self::assertStringContainsString('waits for the decision', $e->getMessage());
        }
        $cards = array_values(array_filter($a['stack']->channel->texts('send'), static fn(string $t): bool => str_contains($t, 'хочет ставить мне задачи')));
        self::assertCount(1, $cards, 'the owner of a is asked');
        self::assertStringContainsString('b', $cards[0]);

        $a['requests']->handleCallback(new \Mercurius\Messenger\IncomingCallback('telegram', 'cb', 'a', 'a', 'Owner', null, 'pa:1:u', 'u1'));
        $out = $b['network']->delegate($b['owner'], 'agent-a', 'Как дела?', 'telegram', 'b', 0, []);

        self::assertNotSame('', $out->remoteId, 'the same connection carries the task now');
        self::assertSame(1, $this->connects());
    }
}
