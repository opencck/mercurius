<?php

declare(strict_types=1);

namespace OpenCCK\Test\Unit\App\Agent\Model;

use Amp\PHPUnit\AsyncTestCase;
use DomainException;
use Mercurius\Llm\ModelCatalog\ModelInfo;
use OpenCCK\App\Agent\Model\ModelRoles;
use OpenCCK\App\Agent\Model\ModelRouter;
use OpenCCK\App\Agent\Model\RequestClassifier;
use OpenCCK\App\Agent\Model\RoleConversations;
use OpenCCK\Domain\Agent\ModelRef;
use OpenCCK\Domain\Agent\ModelRole;
use OpenCCK\Domain\Agent\RoutingDecision;
use OpenCCK\Domain\Agent\RunLimits;
use OpenCCK\Domain\Entity\Member;
use OpenCCK\Domain\Entity\MemberKind;
use OpenCCK\Domain\Entity\Role;
use OpenCCK\Test\Support\AgentStack;
use OpenCCK\Test\Support\InMemoryConversationRepository;
use OpenCCK\Test\Support\InMemoryModelStores;
use OpenCCK\Test\Support\ScriptedLlm;
use Psr\Log\NullLogger;

/**
 * A change of the model of a role reaches the open conversations of that role from their next run.
 *
 * spec: brief/agent-loop.md#модели
 */
final class RoleChangeTest extends AsyncTestCase {
    private const A = 'openrouter:x/a';
    private const B = 'openrouter:x/b';

    private InMemoryModelStores $stores;
    private InMemoryConversationRepository $conversations;
    private ModelRoles $roles;
    private Member $owner;
    private Member $agent;

    protected function setUp(): void {
        parent::setUp();
        $this->stores = new InMemoryModelStores();
        $model = static fn(string $id): ModelInfo => new ModelInfo($id, $id, 100_000, 0.0000001, 0.0000004, ['text'], ['text'], ['tools']);
        $this->stores->replace('openrouter', [$model('x/a'), $model('x/b'), $model('z-ai/glm-5.3-flash'), $model('openai/gpt-5.6-luna')]);
        $this->conversations = new InMemoryConversationRepository();
        $this->roles = new ModelRoles($this->stores, $this->stores, conversations: new RoleConversations($this->conversations, $this->stores, new NullLogger()));
        $this->roles->seed();
        $this->owner = new Member(1, MemberKind::Human, Role::admin(), 'Владелец');
        $this->agent = new Member(2, MemberKind::Human, Role::user(), 'Участник');
        $this->stores->set(ModelRole::STRONG, ModelRef::parse(self::A), 'начальное', ModelRole::SET_BY_AGENT);
    }

    /** An open conversation that got the role when it opened (null — no record of its role). */
    private function open(string $chat, string $model, ?string $role): int {
        $id = $this->conversations->openConversation(1, 'telegram', $chat);
        $this->conversations->setMeta($id, 'промпт', $model);
        if ($role !== null) {
            $this->stores->record(new RoutingDecision($id, $role === ModelRole::STRONG ? 'complex' : 'normal', 'heuristic', $role, $model, 'при открытии'));
        }
        return $id;
    }

    public function testTheNextRunOfAConversationOfTheRoleGoesOnTheNewModel(): void {
        $classifier = new RequestClassifier(new ScriptedLlm(), fn(): string => (string) $this->roles->model(ModelRole::CHEAP), new NullLogger());
        $router = new ModelRouter($classifier, $this->roles, $this->stores, $this->stores, 131072, new NullLogger());
        $stack = new AgentStack(new ScriptedLlm([ScriptedLlm::text('план'), ScriptedLlm::text('дальше')]), router: $router);
        $roles = new ModelRoles($this->stores, $this->stores, conversations: new RoleConversations($stack->conversations, $this->stores, new NullLogger()));

        $stack->say('Спроектируй архитектуру сервиса уведомлений');
        $stack->scheduler->awaitIdle();
        $change = $roles->set(ModelRole::STRONG, self::B, 'владелец попросил убрать дорогую модель', $this->owner, false);
        $stack->say('продолжай');
        $stack->scheduler->awaitIdle();

        self::assertSame([self::A, self::B], [$stack->llm->requests[0]->model, $stack->llm->requests[1]->model]);
        self::assertSame(1, $change->moved);
        $last = end($this->stores->decisions);
        self::assertNotFalse($last);
        self::assertSame([RoleConversations::METHOD, ModelRole::STRONG, self::B, 'роль strong: ' . self::A . ' → ' . self::B], [$last->method, $last->role, $last->model, $last->reason]);
    }

    public function testOnlyTheConversationsOfTheChangedRoleMoveWhenOneModelStandsInTwo(): void {
        $this->stores->set(ModelRole::MAIN, ModelRef::parse(self::A), 'одна модель на двух ролях', ModelRole::SET_BY_AGENT);
        $strong = $this->open('1', self::A, ModelRole::STRONG);
        $main = $this->open('2', self::A, ModelRole::MAIN);
        $unknown = $this->open('3', self::A, null);
        $closed = $this->open('4', self::A, ModelRole::STRONG);
        $this->conversations->close('telegram', '4');

        $change = $this->roles->set(ModelRole::STRONG, self::B, 'владелец попросил убрать дорогую модель', $this->owner, false);

        self::assertSame(1, $change->moved);
        self::assertSame(self::B, $this->conversations->meta($strong)?->model);
        self::assertSame(self::A, $this->conversations->meta($main)?->model, 'main keeps the model it stood on');
        self::assertSame(self::A, $this->conversations->meta($unknown)?->model, 'no record and the model in two roles: not moved');
        self::assertSame(self::A, $this->conversations->meta($closed)?->model, 'a closed conversation is not touched');
    }

    public function testAConversationWithNoRecordMovesWhenTheOldModelStoodInThisRoleOnly(): void {
        $unknown = $this->open('5', 'x/a', null);

        $this->roles->set(ModelRole::STRONG, self::B, 'владелец попросил убрать дорогую модель', $this->owner, false);

        self::assertSame(self::B, $this->conversations->meta($unknown)?->model, 'a model stored without its provider is the same model');
    }

    public function testARunAlreadyGoingKeepsItsModel(): void {
        $id = $this->open('6', self::A, ModelRole::STRONG);
        $runs = new \OpenCCK\Test\Support\InMemoryRunRepository();
        $run = $runs->start($id, 1, self::A, new RunLimits(30, 100_000, 900, 120));
        self::assertNotNull($run);

        $this->roles->set(ModelRole::STRONG, self::B, 'владелец попросил убрать дорогую модель', $this->owner, false);

        self::assertSame(self::A, $runs->find($run->id)?->model, 'the run keeps the model it started with');
        self::assertSame(self::B, $this->conversations->meta($id)?->model, 'the next run takes the new one');
    }

    public function testARefusedChangeMovesNothing(): void {
        $id = $this->open('7', self::A, ModelRole::STRONG);
        $this->stores->set(ModelRole::STRONG, ModelRef::parse(self::A), 'зафиксировал владелец', ModelRole::SET_BY_OWNER);
        $before = count($this->stores->decisions);

        foreach ([
            fn(): mixed => $this->roles->set(ModelRole::STRONG, self::B, 'агент хочет дешевле на тех же задачах', $this->agent, false),
            fn(): mixed => $this->roles->set(ModelRole::STRONG, 'openrouter:x/nowhere', 'владелец хочет новую модель', $this->owner, false),
        ] as $refused) {
            try {
                $refused();
                self::fail('the change went through');
            } catch (DomainException) {
            }
        }

        self::assertSame(self::A, $this->conversations->meta($id)?->model);
        self::assertCount($before, $this->stores->decisions, 'no move recorded');
    }
}
