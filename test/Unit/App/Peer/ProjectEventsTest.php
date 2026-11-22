<?php

declare(strict_types=1);

namespace OpenCCK\Test\Unit\App\Peer;

use Amp\PHPUnit\AsyncTestCase;
use OpenCCK\App\Peer\ProjectEvents;
use OpenCCK\Domain\Agent\RunLimits;
use OpenCCK\Domain\Entity\Principal;
use OpenCCK\Domain\Entity\Role;
use OpenCCK\Test\Support\AgentStack;
use OpenCCK\Test\Support\InMemoryProjectRepository;
use OpenCCK\Test\Support\ScriptedLlm;

/**
 * spec: brief/network.md#события-проекта-для-агентов
 */
final class ProjectEventsTest extends AsyncTestCase {
    private AgentStack $stack;
    private InMemoryProjectRepository $projects;
    private Principal $owner;
    private Principal $agent;
    /** @var list<array{int, string, array{project: mixed, place: mixed, run: int, from: string, text: string, answer: string, status: string, at: string}}> */
    private array $pushed = [];
    private ProjectEvents $events;

    protected function setUp(): void {
        parent::setUp();
        $this->stack = new AgentStack(new ScriptedLlm([]));
        $this->owner = $this->stack->principals->ensureOwner('telegram', '42', 'Owner');
        $this->agent = $this->stack->principals->createAgent('helper', Role::user());
        $this->projects = new InMemoryProjectRepository();
        $this->events = new ProjectEvents(
            $this->stack->runs,
            $this->stack->conversations,
            $this->stack->transcript,
            $this->projects,
            $this->stack->principals,
            function (int $principalId, string $method, array $params): bool {
                /** @var array{project: mixed, place: mixed, run: int, from: string, text: string, answer: string, status: string, at: string} $params */
                $this->pushed[] = [$principalId, $method, $params];
                return true;
            },
        );
    }

    /** @return list<array{int, string, array<string, mixed>}> what went out so far */
    private function pushed(): array {
        return $this->pushed;
    }

    /** A finished run of $who in a chat: what was asked and what was answered. */
    private function exchange(Principal $who, string $chat, string $thread, string $asked, string $answered): int {
        $conversation = $this->stack->conversations->openConversation($who->id, 'telegram', $chat, $thread);
        $run = $this->stack->runs->start($conversation, $who->id, 'test/model', new RunLimits(30, 100_000, 900, 120)) ?? self::fail('no run');
        $this->stack->transcript->appendUser($conversation, $run->id, $asked);
        $this->stack->transcript->appendAssistant($conversation, $run->id, $answered, []);
        $this->stack->runs->setStatus($run->id, \OpenCCK\Domain\Agent\RunStatus::Done);
        return $run->id;
    }

    public function testAnAgentOfAProjectLearnsWhatWasSaidInItsTopic(): void {
        $project = $this->projects->create('Поиск', 'работа');
        $this->projects->activate('telegram', '-100500', $project->id, '4');
        $this->projects->share($project->id, $this->agent->id, 'read', null);

        $this->events->finished($this->exchange($this->owner, '-100500', '4', 'Что с откликами?', 'Отправлено три.'));

        self::assertCount(1, $this->pushed);
        [$to, $method, $params] = $this->pushed[0];
        self::assertSame([$this->agent->id, 'ProjectEvent'], [$to, $method]);
        self::assertSame(['id' => $project->id, 'name' => 'Поиск'], $params['project']);
        self::assertSame(['channel' => 'telegram', 'chat' => '-100500', 'thread' => '4'], $params['place']);
        self::assertSame('Что с откликами?', $params['text']);
        self::assertSame('Отправлено три.', $params['answer']);
        self::assertStringContainsString('Owner', $params['from']);
    }

    public function testNothingGoesOutOfAChatWithoutAProjectOrToAStranger(): void {
        $project = $this->projects->create('Поиск', 'работа');
        $this->projects->activate('telegram', '-100500', $project->id, '4');

        $this->events->finished($this->exchange($this->owner, '-100500', '4', 'вопрос', 'ответ'));
        self::assertSame([], $this->pushed, 'the agent has no access to the project');

        $this->projects->share($project->id, $this->agent->id, 'read', null);
        $this->events->finished($this->exchange($this->owner, '42', '', 'личное', 'ответ'));
        self::assertSame([], $this->pushed, 'the owner\'s private chat is not a chat of the project');
    }

    public function testAnAgentIsNotToldItsOwnWordsAndAnOwnerAgentIsTold(): void {
        $mine = $this->projects->create('Поиск', 'работа', $this->agent->id);
        $this->projects->activate('telegram', '-100500', $mine->id, '4');

        $this->events->finished($this->exchange($this->agent, '-100500', '4', 'сам спросил', 'ответ'));
        self::assertSame([], $this->pushed, 'whoever wrote knows it already');

        $this->events->finished($this->exchange($this->owner, '-100500', '4', 'вопрос владельца', 'ответ'));
        self::assertSame([$this->agent->id], array_column($this->pushed, 0), 'the agent that owns the project is told too');
    }

    public function testASecretIsNotCarriedInAnEvent(): void {
        $project = $this->projects->create('Поиск', 'работа');
        $this->projects->activate('telegram', '-100500', $project->id, '4');
        $this->projects->share($project->id, $this->agent->id, 'write', null);

        $this->events->finished($this->exchange($this->owner, '-100500', '4', 'пароль: hunter2secret', 'Записал.'));

        self::assertStringNotContainsString('hunter2secret', json_encode($this->pushed, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }

    public function testANeighbourSubscribedToATopicHearsItAndNothingOfOtherPlaces(): void {
        // spec: brief/network.md#потоки-мест-для-агентов
        $streams = new \OpenCCK\Test\Support\InMemoryPlaceStreamRepository();
        $this->events->useStreams($streams);
        $commands = new \OpenCCK\App\Peer\StreamCommands($streams, $this->stack->principals, new \Psr\Log\NullLogger(), $this->projects);
        $inTopic = new \Mercurius\Messenger\IncomingMessage('telegram', '-100500', '42', 'owner', 'Owner', '/stream give helper', '1', null, false, new \DateTimeImmutable(), '1', '4', raw: ['is_topic_message' => true]);
        self::assertStringContainsString('дан поток', $commands->handle($this->owner, $inTopic, '/stream', 'give helper'));
        $stream = $streams->of($this->agent->id)[0];

        $this->events->finished($this->exchange($this->owner, '-100500', '4', 'до подписки', 'ответ'));
        self::assertSame([], $this->pushed, 'given but not subscribed yet');
        $streams->setSubscribed($stream->id, true);

        $this->events->finished($this->exchange($this->owner, '-100500', '4', 'в теме', 'ответ в теме'));
        $this->events->finished($this->exchange($this->owner, '-100500', '9', 'в другой теме', 'ответ'));
        $this->events->finished($this->exchange($this->owner, '-100777', '', 'в другом чате', 'ответ'));

        $pushed = $this->pushed();
        self::assertCount(1, $pushed);
        [$to, $method, $params] = $pushed[0];
        self::assertSame([$this->agent->id, 'PlaceEvent'], [$to, $method]);
        self::assertSame('в теме', $params['text']);
        self::assertSame($stream->id, $params['stream'] ?? null);

        self::assertStringContainsString('отозван', $commands->handle($this->owner, $inTopic, '/stream', 'take helper'));
        $this->events->finished($this->exchange($this->owner, '-100500', '4', 'после отзыва', 'ответ'));
        self::assertCount(1, $this->pushed(), 'taken back: the subscription ended at once');
    }

    public function testTheOwnerGivesANeighbourTheStreamOfThePrivateChat(): void {
        // spec: brief/network.md#потоки-мест-для-агентов
        $streams = new \OpenCCK\Test\Support\InMemoryPlaceStreamRepository();
        $this->events->useStreams($streams);
        $commands = new \OpenCCK\App\Peer\StreamCommands($streams, $this->stack->principals, new \Psr\Log\NullLogger(), $this->projects);
        $private = new \Mercurius\Messenger\IncomingMessage('telegram', '42', '42', 'owner', 'Owner', '/stream give helper', '2', null, true, new \DateTimeImmutable(), '2');
        self::assertStringContainsString('личка владельца', $commands->handle($this->owner, $private, '/stream', 'give helper'));
        $streams->setSubscribed($streams->of($this->agent->id)[0]->id, true);

        $this->events->finished($this->exchange($this->owner, '42', '', 'в личке', 'ответ в личке'));
        $this->events->finished($this->exchange($this->owner, '-100500', '', 'в группе', 'ответ'));
        $pushed = $this->pushed();
        self::assertCount(1, $pushed);
        self::assertSame(['PlaceEvent', 'в личке'], [$pushed[0][1], $pushed[0][2]['text']]);

        self::assertStringContainsString('отозван', $commands->handle($this->owner, $private, '/stream', 'take helper'));
        self::assertSame([], $streams->of($this->agent->id));
    }
}
