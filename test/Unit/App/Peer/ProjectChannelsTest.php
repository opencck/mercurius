<?php

declare(strict_types=1);

namespace OpenCCK\Test\Unit\App\Peer;

use Amp\Http\Client\HttpClient;
use Amp\NullCancellation;
use Amp\PHPUnit\AsyncTestCase;
use OpenCCK\App\Agent\Tool\Guard\AddressGuard;
use OpenCCK\App\Agent\Tool\Projects\ProjectEditTool;
use OpenCCK\App\Agent\Tool\ToolContext;
use OpenCCK\App\Agent\Tool\Web\BrowserTool;
use OpenCCK\App\Agent\Tool\Web\HttpRequestTool;
use OpenCCK\App\Peer\A2aServer;
use Mercurius\Peer\AgentIdentity;
use OpenCCK\App\Peer\ProjectChannels;
use OpenCCK\App\Peer\ProjectEvents;
use OpenCCK\App\Vault\Vault;
use OpenCCK\Domain\Agent\DangerClass;
use OpenCCK\Domain\Agent\RunLimits;
use OpenCCK\Domain\Agent\RunStatus;
use OpenCCK\Domain\Entity\Principal;
use OpenCCK\Domain\Entity\Role;
use OpenCCK\Domain\Project\Project;
use OpenCCK\Domain\Project\ProjectLevel;
use OpenCCK\Infrastructure\Browser\BrowserClient;
use OpenCCK\Test\Support\AgentStack;
use OpenCCK\Test\Support\FakeChannel;
use OpenCCK\Test\Support\FakeHttpClient;
use OpenCCK\Test\Support\InMemoryPeerRepository;
use OpenCCK\Test\Support\InMemoryProjectLinkRepository;
use OpenCCK\Test\Support\InMemoryProjectRepository;
use OpenCCK\Test\Support\InMemorySecretRepository;
use OpenCCK\Test\Support\ScriptedLlm;
use Psr\Log\NullLogger;

/**
 * A project as a channel for agents and as the place of shared things: an agent sees its projects, listens to one,
 * works in one; what the owner gave to the project — a browser login, a secret — serves those who work there, and
 * what is done with it is confirmed by the one it belongs to.
 *
 * spec: brief/network.md#проект-как-канал-для-агентов
 * spec: brief/participants.md#ресурсы-проекта
 */
final class ProjectChannelsTest extends AsyncTestCase {
    private AgentStack $stack;
    private InMemoryProjectRepository $projects;
    private InMemoryProjectLinkRepository $links;
    private ProjectChannels $channels;
    private Principal $owner;
    private Principal $agent;
    private Principal $other;
    private Project $project;
    /** @var list<array{int, string, array<string, mixed>}> */
    private array $pushed = [];

    protected function setUp(): void {
        parent::setUp();
        $this->stack = new AgentStack(new ScriptedLlm([ScriptedLlm::text('Готово.')]));
        $this->owner = $this->stack->principals->ensureOwner('telegram', '42', 'Owner');
        $this->agent = $this->stack->principals->createAgent('helper', Role::user());
        $this->other = $this->stack->principals->createAgent('stranger', Role::user());
        $this->projects = new InMemoryProjectRepository();
        $this->links = new InMemoryProjectLinkRepository();
        $this->channels = new ProjectChannels($this->projects, $this->links);
        $this->project = $this->projects->create('Job Hunt', 'работа');
        $this->projects->share($this->project->id, $this->agent->id, 'full', null);
    }

    private function server(): A2aServer {
        $server = new A2aServer(
            new InMemoryPeerRepository(),
            $this->stack->principals,
            $this->stack->loop,
            $this->stack->runs,
            $this->stack->transcript,
            $this->stack->conversations,
            $this->stack->policies,
            'test/model',
            'https://self.example',
            'Self',
            'test',
            new NullLogger(),
            AgentIdentity::generate(),
            'entrance-token-entrance-token',
        );
        $server->useProjects($this->channels);
        return $server;
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function call(A2aServer $server, Principal $agent, string $method, array $params = []): array {
        [, $answer] = $server->handle($agent, ['jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => $params]);
        return $answer;
    }

    /**
     * The list of projects an answer carries.
     *
     * @param array<string, mixed> $params
     * @return list<array{id: int, name: string, access: string, subscribed: bool, working: bool}>|null null — the call was refused
     */
    private function listed(A2aServer $server, Principal $agent, string $method, array $params = []): ?array {
        $answer = $this->call($server, $agent, $method, $params);
        if (isset($answer['error'])) {
            return null;
        }
        self::assertIsArray($answer['result'] ?? null);
        /** @var array{projects: list<array{id: int, name: string, access: string, subscribed: bool, working: bool}>} $result */
        $result = $answer['result'];
        return $result['projects'];
    }

    public function testAnAgentListsItsProjectsSubscribesAndLeaves(): void {
        $server = $this->server();

        self::assertSame([['id' => $this->project->id, 'name' => 'Job Hunt', 'access' => ProjectLevel::Full->label(), 'subscribed' => false, 'working' => false]], $this->listed($server, $this->agent, 'ListProjects'));
        self::assertSame([], $this->listed($server, $this->other, 'ListProjects'), 'a project is seen by those who work with it');

        $subscribed = $this->listed($server, $this->agent, 'SubscribeProject', ['project' => 'Job Hunt']) ?? [];
        self::assertSame([true, false], [$subscribed[0]['subscribed'] ?? null, $subscribed[0]['working'] ?? null], 'listening is not working in the project');

        $working = $this->listed($server, $this->agent, 'SubscribeProject', ['project' => $this->project->id, 'work' => true]) ?? [];
        self::assertTrue($working[0]['working'] ?? false);
        self::assertSame($this->project->id, $this->channels->working($this->agent)?->id);

        self::assertNull($this->listed($server, $this->other, 'SubscribeProject', ['project' => 'Job Hunt']), 'not their project');
        self::assertFalse($this->links->subscribed($this->project->id, $this->other->id));

        $left = $this->listed($server, $this->agent, 'UnsubscribeProject', ['project' => 'Job Hunt']) ?? [];
        self::assertSame([false, false], [$left[0]['subscribed'] ?? null, $left[0]['working'] ?? null]);
        self::assertNull($this->channels->working($this->agent));
    }

    public function testATaskOfAnAgentRunsInItsWorkingProject(): void {
        $server = $this->server();
        $send = fn(string $id, array $metadata = []): array => $this->call($server, $this->agent, 'SendMessage', ['message' => ['messageId' => $id, 'contextId' => 'ctx-' . $id, 'parts' => [['kind' => 'text', 'text' => 'Проверь hh']]] + ($metadata === [] ? [] : ['metadata' => $metadata])]);

        $send('m1');
        self::assertNull($this->projects->active('a2a', 'a2a-' . $this->agent->id . '-ctx-m1'), 'no working project: the task is in no project');

        $this->channels->enter($this->agent, $this->project);
        self::assertTrue($this->links->subscribed($this->project->id, $this->agent->id), 'entering is also listening');
        $send('m2');
        self::assertSame($this->project->id, $this->projects->active('a2a', 'a2a-' . $this->agent->id . '-ctx-m2')?->id);

        // a task may name its project itself; a project of someone else is refused
        $this->channels->leave($this->agent);
        $send('m3', ['project' => 'Job Hunt']);
        self::assertSame($this->project->id, $this->projects->active('a2a', 'a2a-' . $this->agent->id . '-ctx-m3')?->id);
        $foreign = $this->projects->create('Чужой', 'не его');
        $refused = $send('m4', ['project' => (string) $foreign->id]);
        self::assertStringContainsString('reject', strtolower(json_encode($refused, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)));
        self::assertNull($this->projects->active('a2a', 'a2a-' . $this->agent->id . '-ctx-m4'));

        // access taken back: the agent is out of the project
        $this->channels->enter($this->agent, $this->project);
        $this->projects->unshare($this->project->id, $this->agent->id);
        self::assertNull($this->channels->working($this->agent));
        \Amp\delay(0.02);
    }

    public function testAnAgentEntersAProjectFromItsOwnTask(): void {
        $edit = new ProjectEditTool($this->projects, $this->stack->conversations, principals: $this->stack->principals);
        $edit->useChannels($this->channels);
        $conversation = $this->stack->conversations->openConversation($this->agent->id, 'a2a', 'a2a-' . $this->agent->id . '-ctx-1');
        $context = new ToolContext(1, $conversation, $this->agent, new NullCancellation());

        $entered = $edit->execute(['action' => 'activate', 'project' => 'Job Hunt'], $context);
        self::assertFalse($entered->isError, $entered->content);
        self::assertSame($this->project->id, $this->channels->working($this->agent)?->id, 'the next tasks of the agent run in the project too');

        self::assertFalse($edit->execute(['action' => 'deactivate'], $context)->isError);
        self::assertNull($this->channels->working($this->agent));
    }

    /** @return list<int> the agents an event was sent to */
    private function told(): array {
        return array_map(static fn(array $event): int => $event[0], $this->pushed);
    }

    public function testWhatIsSaidInAProjectReachesTheAgentsThatSubscribed(): void {
        $this->projects->activate('telegram', '-100500', $this->project->id, '4');
        $events = new ProjectEvents(
            $this->stack->runs,
            $this->stack->conversations,
            $this->stack->transcript,
            $this->projects,
            $this->stack->principals,
            function (int $principalId, string $method, array $params): bool {
                /** @var array<string, mixed> $params */
                $this->pushed[] = [$principalId, $method, $params];
                return true;
            },
            new NullLogger(),
            $this->channels->subscribed(...),
        );
        $say = function () use ($events): void {
            $conversation = $this->stack->conversations->openConversation($this->owner->id, 'telegram', '-100500', '4');
            $run = $this->stack->runs->start($conversation, $this->owner->id, 'test/model', new RunLimits(30, 100_000, 900, 120)) ?? self::fail('no run');
            $this->stack->transcript->appendUser($conversation, $run->id, 'Что с откликами?');
            $this->stack->transcript->appendAssistant($conversation, $run->id, 'Отправлено три.', []);
            $this->stack->runs->setStatus($run->id, RunStatus::Done);
            $events->finished($run->id);
        };

        $say();
        self::assertSame([], $this->told(), 'a member that did not subscribe hears nothing');

        $this->channels->subscribe($this->agent, $this->project);
        $say();
        self::assertSame([$this->agent->id], $this->told());
    }

    public function testASecretOfAProjectServesItsSiteAndIsConfirmedByItsAuthor(): void {
        // spec: brief/participants.md#секреты-проекта
        $vault = new Vault(new InMemorySecretRepository(), random_bytes(32));
        $vault->putProject($this->project->id, 'hh-token', 'hh.ru', 'hh-secret-value-123', $this->owner);

        self::assertSame($this->owner->id, $vault->sharedWith('hh-token', $this->project->id, 'api.hh.ru'));
        self::assertNull($vault->sharedWith('hh-token', $this->project->id, 'evil.example'), 'a secret of a project never leaves for another site');
        self::assertNull($vault->sharedWith('hh-token', $this->project->id, 'nothh.ru'));
        self::assertNull($vault->sharedWith('hh-token', $this->project->id + 1, 'hh.ru'));
        self::assertNull($vault->reveal('hh-token', $this->agent), 'the agent still cannot read it as its own');

        $http = new FakeHttpClient(['https://api.hh.ru/me' => [200, ['content-type' => 'application/json'], '{"ok":true}']]);
        $inProject = true;
        $tool = new HttpRequestTool(
            $http,
            new AddressGuard(static fn(string $host): array => ['93.184.216.34']),
            $vault,
            projectOf: function (int $conversationId, Principal $who) use (&$inProject): ?int {
                /** @var bool $inProject */
                return $inProject && $who->id === $this->agent->id ? $this->project->id : null;
            },
            principals: $this->stack->principals,
        );
        $prepared = $tool->prepareIn(['url' => 'https://api.hh.ru/me', 'secret' => 'hh-token', 'secret_owner' => 999], $this->agent, 1, new NullCancellation());
        self::assertSame($this->owner->id, $prepared['secret_owner'] ?? null, 'what a model wrote there is replaced');
        self::assertSame($this->owner->id, $tool->decider($prepared)?->id, 'the one who stored the secret confirms the request');
        self::assertSame(DangerClass::Irreversible, $tool->danger($prepared));

        $elsewhere = $tool->prepareIn(['url' => 'https://evil.example/', 'secret' => 'hh-token'], $this->agent, 1, new NullCancellation());
        self::assertArrayNotHasKey('secret_owner', $elsewhere);
        self::assertTrue($tool->execute($elsewhere, new ToolContext(1, 1, $this->agent, new NullCancellation()))->isError);

        $result = $tool->execute($prepared, new ToolContext(1, 1, $this->agent, new NullCancellation()));
        self::assertFalse($result->isError, $result->content);
        self::assertSame('Bearer hh-secret-value-123', $http->headers[0]['authorization'] ?? null);
        self::assertStringNotContainsString('hh-secret-value-123', $result->content);

        // out of the project: the mark on the call no longer opens the secret
        $inProject = false;
        self::assertTrue($tool->execute($prepared, new ToolContext(1, 1, $this->agent, new NullCancellation()))->isError);

        $inProject = true;
        self::assertTrue($vault->removeProject('hh-token', $this->project->id));
        self::assertTrue($tool->execute($prepared, new ToolContext(1, 1, $this->agent, new NullCancellation()))->isError);
    }

    public function testWithSecretsOfTheSameNameTheRequestGoesWithTheProjectsOne(): void {
        // spec: brief/participants.md#секреты-проекта
        $vault = new Vault(new InMemorySecretRepository(), random_bytes(32));
        $vault->put('hh-token', 'token', 'personal-value-111', $this->agent);
        $vault->putProject($this->project->id, 'hh-token', 'hh.ru', 'project-value-222', $this->owner);
        $http = new FakeHttpClient(['https://api.hh.ru/me' => [200, ['content-type' => 'application/json'], '{"ok":true}']]);
        $tool = new HttpRequestTool(
            $http,
            new AddressGuard(static fn(string $host): array => ['93.184.216.34']),
            $vault,
            projectOf: fn(int $conversationId, Principal $who): int => $this->project->id,
            principals: $this->stack->principals,
        );

        $prepared = $tool->prepareIn(['url' => 'https://api.hh.ru/me', 'secret' => 'hh-token'], $this->agent, 1, new NullCancellation());
        $result = $tool->execute($prepared, new ToolContext(1, 1, $this->agent, new NullCancellation()));

        self::assertFalse($result->isError, $result->content);
        self::assertSame('Bearer project-value-222', $http->headers[0]['authorization'] ?? null, 'the project\'s secret wins over the personal one');
        self::assertSame('personal-value-111', $vault->reveal('hh-token', $this->agent), 'the personal secret stays as it was');
    }

    public function testAClickUnderSomeoneElsesLoginIsTheirsToAllow(): void {
        $tool = new BrowserTool(
            new BrowserClient(new HttpClient(new FakeHttpClient([]), []), 'http://browser:3000', 'token-token-token'),
            new FakeChannel(),
            '42',
            projectOf: fn(int $conversationId): ?int => $conversationId === 7 ? $this->project->id : null,
            holderOf: fn(int $projectId, Principal $who): int => $this->owner->id,
            principals: $this->stack->principals,
        );

        $own = $tool->prepareIn(['action' => 'click', 'ref' => 3, 'holder' => 999], $this->owner, 7, new NullCancellation());
        self::assertArrayNotHasKey('holder', $own);
        self::assertSame(DangerClass::Write, $tool->danger($own), 'in their own profile the installation\'s rule stands');
        self::assertNull($tool->decider($own));

        $foreign = $tool->prepareIn(['action' => 'click', 'ref' => 3], $this->agent, 7, new NullCancellation());
        self::assertSame($this->owner->id, $foreign['holder'] ?? null);
        self::assertSame(DangerClass::Irreversible, $tool->danger($foreign));
        self::assertSame($this->owner->id, $tool->decider($foreign)?->id);
        self::assertSame(DangerClass::Irreversible, $tool->danger(['action' => 'type', 'ref' => 1, 'text' => 'x', 'submit' => true] + $foreign));
        self::assertSame(DangerClass::Read, $tool->danger(['action' => 'read'] + $foreign), 'reading the project\'s pages needs no card');

        self::assertArrayNotHasKey('holder', $tool->prepareIn(['action' => 'click', 'ref' => 3], $this->agent, 8, new NullCancellation()), 'outside the project the profile is the agent\'s own');
    }
}
