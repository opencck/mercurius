<?php

declare(strict_types=1);

namespace OpenCCK\Test\Unit\App\Agent;

use Amp\NullCancellation;
use Amp\PHPUnit\AsyncTestCase;
use OpenCCK\App\Agent\ProjectContext;
use OpenCCK\App\Agent\Tool\Projects\ProjectEditTool;
use OpenCCK\App\Agent\Tool\Projects\ProjectTool;
use OpenCCK\App\Agent\Tool\ToolContext;
use OpenCCK\App\Service\ProjectCommands;
use OpenCCK\Domain\Agent\DangerClass;
use OpenCCK\Domain\Entity\Principal;
use OpenCCK\Domain\Entity\PrincipalKind;
use OpenCCK\Domain\Entity\Role;
use OpenCCK\Domain\Project\Project;
use OpenCCK\Domain\Project\ProjectTask;
use OpenCCK\Test\Support\AgentStack;
use OpenCCK\Test\Support\InMemoryConversationRepository;
use OpenCCK\Test\Support\InMemoryProjectRepository;
use OpenCCK\Test\Support\ScriptedLlm;

final class ProjectsTest extends AsyncTestCase {
    private InMemoryProjectRepository $projects;
    private InMemoryConversationRepository $conversations;
    private Principal $owner;
    private int $conversation;

    protected function setUp(): void {
        parent::setUp();
        $this->projects = new InMemoryProjectRepository();
        $this->conversations = new InMemoryConversationRepository();
        $this->owner = new Principal(1, PrincipalKind::Human, Role::owner(), null);
        $this->conversation = $this->conversations->openConversation(1, 'telegram', '42');
    }

    /** @param array<string, mixed> $args */
    private function edit(array $args, ?Principal $who = null): \OpenCCK\App\Agent\Tool\ToolResult {
        return (new ProjectEditTool($this->projects, $this->conversations))->execute($args, new ToolContext(1, $this->conversation, $who ?? $this->owner, new NullCancellation()));
    }

    public function testInAForumAProjectGetsItsTopicAndClosesItWhenDone(): void {
        $channel = new \OpenCCK\Test\Support\FakeChannel();
        $groups = new \OpenCCK\Test\Support\InMemoryGroupRepository();
        $groups->create('telegram', '-100500', 'Дом');
        $groups->decide('telegram', '-100500', \OpenCCK\Domain\Group\Group::APPROVED, \OpenCCK\Domain\Group\Group::MENTION);
        $groups->setForum('telegram', '-100500', true);
        $forum = new \OpenCCK\App\Service\ForumTopics($channel, $groups, $this->projects, new \Psr\Log\NullLogger());
        $conversation = $this->conversations->openConversation(1, 'telegram', '-100500');
        $tool = new ProjectEditTool($this->projects, $this->conversations, forum: $forum);
        $context = new ToolContext(1, $conversation, $this->owner, new NullCancellation());

        $created = $tool->execute(['action' => 'create', 'project' => 'Сайт', 'text' => 'Магазин'], $context);
        $done = $tool->execute(['action' => 'status', 'project' => 'Сайт', 'status' => 'done'], $context);

        self::assertStringContainsString('Создал тему «Сайт»', $created->content);
        self::assertFalse($done->isError);
        self::assertSame(['create', 'close'], array_column($channel->topics, 0));
    }

    public function testGoalStatusNewProjectAndRelationWaitForTheOwner(): void {
        $tool = new ProjectEditTool($this->projects, $this->conversations);

        foreach (['create', 'goal', 'status', 'relation'] as $action) {
            self::assertSame(DangerClass::Irreversible, $tool->danger(['action' => $action]), $action);
        }
        foreach (['card', 'add_task', 'task_status', 'task_due', 'activate'] as $action) {
            self::assertSame(DangerClass::Write, $tool->danger(['action' => $action]), $action);
        }
    }

    public function testTheAgentKeepsTheCardAndTasksOfTheChatsProject(): void {
        $this->edit(['action' => 'create', 'project' => 'Меркурий', 'text' => 'Агент на сервере']);
        $this->edit(['action' => 'activate', 'project' => 'Меркурий']);

        self::assertFalse($this->edit(['action' => 'card', 'text' => 'PHP 8.2, AMPHP, PostgreSQL'])->isError);
        self::assertStringContainsString('Задача #1', $this->edit(['action' => 'add_task', 'text' => 'Память', 'profile' => ['reasoning' => 6]])->content);
        $this->edit(['action' => 'add_task', 'text' => 'HRR', 'parent_id' => 1]);
        self::assertTrue($this->edit(['action' => 'task_status', 'task_id' => 2, 'status' => 'done'])->isError, 'a closed task needs a result');
        self::assertFalse($this->edit(['action' => 'task_status', 'task_id' => 2, 'status' => 'done', 'text' => 'замер записан'])->isError);

        $project = $this->projects->byName('меркурий');
        self::assertNotNull($project);
        self::assertSame('PHP 8.2, AMPHP, PostgreSQL', $project->card);
        self::assertSame(['reasoning' => 6], $this->projects->task(1)?->profile);
        self::assertSame(ProjectTask::DONE, $this->projects->task(2)?->status);
        self::assertSame(['Память'], array_map(static fn(ProjectTask $t): string => $t->title, $this->projects->openTasks($project->id, 10)));
    }

    public function testDeadlinesAreReadInTheOwnersZone(): void {
        $zone = new \DateTimeZone('Asia/Bangkok');
        self::assertEquals(new \DateTimeImmutable('2026-11-20 16:59 UTC'), ProjectEditTool::due('2026-11-20', $zone), 'a date is the end of that day');
        self::assertEquals(new \DateTimeImmutable('2026-11-20 03:30 UTC'), ProjectEditTool::due('2026-11-20T10:30', $zone));
        self::assertNull(ProjectEditTool::due('в пятницу', $zone));
        self::assertNull(ProjectEditTool::due('2026-13-45', $zone));

        $this->edit(['action' => 'create', 'project' => 'Дом']);
        self::assertStringContainsString('срок 2026-11-20 23:59', $this->edit(['action' => 'add_task', 'project' => 'Дом', 'text' => 'Налог', 'due' => '2026-11-20'])->content);
        self::assertTrue($this->edit(['action' => 'add_task', 'project' => 'Дом', 'text' => 'Счёт', 'due' => 'завтра'])->isError);
        self::assertSame('Срок задачи #1 снят.', $this->edit(['action' => 'task_due', 'project' => 'Дом', 'task_id' => 1, 'due' => ''])->content);
        self::assertNull($this->projects->task(1)?->dueAt);
    }

    public function testSecretsAndForeignTasksAreRefused(): void {
        $this->edit(['action' => 'create', 'project' => 'А', 'text' => '']);
        $this->edit(['action' => 'create', 'project' => 'Б', 'text' => '']);
        $this->edit(['action' => 'add_task', 'project' => 'Б', 'text' => 'чужая']);

        self::assertTrue($this->edit(['action' => 'card', 'project' => 'А', 'text' => 'ключ sk-or-v1-0123456789abcdef0123456789abcdef0123456789abcdef'])->isError);
        self::assertTrue($this->edit(['action' => 'task_status', 'project' => 'А', 'task_id' => 1, 'status' => 'doing'])->isError);
        self::assertTrue($this->edit(['action' => 'card', 'text' => 'без проекта'])->isError, 'no project named, none bound to the chat');
        self::assertTrue($this->edit(['action' => 'create', 'project' => 'а', 'text' => ''])->isError);
    }

    public function testContextGivesTheCardTheWritersRelationAndTasksButNothingToVisitors(): void {
        $project = $this->projects->create('Меркурий', 'Агент на сервере');
        $this->projects->update($project->id, null, null, 'PHP 8.2');
        $this->projects->addTask($project->id, 'Память', null, []);
        $this->projects->setRelation($project->id, 1, 'Главный проект, качество важнее сроков');
        $this->projects->setRelation($project->id, 2, 'Чужое отношение');
        $this->projects->activate('telegram', '42', $project->id);
        $context = new ProjectContext($this->projects);

        $block = $context->block($this->owner, 'telegram', '42');

        self::assertStringContainsString('Активный проект: Меркурий (идёт)', $block);
        self::assertStringContainsString('Цель: Агент на сервере', $block);
        self::assertStringContainsString('PHP 8.2', $block);
        self::assertStringContainsString('Главный проект', $block);
        self::assertStringNotContainsString('Чужое отношение', $block);
        self::assertStringContainsString('#1 Память', $block);
        self::assertSame('', $context->block(new Principal(3, PrincipalKind::Human, Role::visitor(), null), 'telegram', '42'));
        self::assertSame('', $context->block($this->owner, 'telegram', '43'));
    }

    public function testOwnerCommandsBindAChat(): void {
        $commands = new ProjectCommands($this->projects);

        self::assertStringContainsString('Проектов пока нет', $commands->list('telegram', '42'));
        self::assertStringContainsString('Заведён проект. «Сайт» — проект этого чата', $commands->project('telegram', '42', ' Сайт'));
        self::assertStringContainsString('▶ Сайт — идёт', $commands->list('telegram', '42'));
        self::assertStringContainsString('Проект чата: Сайт', $commands->project('telegram', '42', ''));
        self::assertStringNotContainsString('Заведён', $commands->project('telegram', '43', 'сайт'));
        $commands->project('telegram', '42', '-');
        self::assertNull($this->projects->active('telegram', '42'));
    }

    public function testListShowsTheChatsProject(): void {
        $this->projects->create('Альфа', 'Поддержка сайта');
        $beta = $this->projects->create('Бета', '');
        $this->projects->update($beta->id, null, Project::PAUSED, null);
        $this->projects->activate('telegram', '42', $beta->id);

        $list = (new ProjectTool($this->projects, $this->conversations))->execute([], new ToolContext(1, $this->conversation, $this->owner, new NullCancellation()))->content;

        self::assertStringContainsString('Альфа — идёт; цель: Поддержка сайта; открытых задач: 0', $list);
        self::assertStringContainsString('Бета — на паузе; открытых задач: 0 (проект этого чата)', $list);
    }

    public function testANewConversationOfTheChatGetsTheProjectInItsPrompt(): void {
        $stack = new AgentStack(new ScriptedLlm([ScriptedLlm::text('ок')]));
        $project = $this->projects->create('Меркурий', 'Агент на сервере');
        $this->projects->activate('telegram', '42', $project->id);
        $stack->scheduler->useProjects(new ProjectContext($this->projects));

        $stack->say('привет');
        $stack->scheduler->awaitIdle();

        $system = $stack->llm->requests[0]->messages[0]->content;
        self::assertStringContainsString('<untrusted-data source="project">', $system);
        self::assertStringContainsString('Активный проект: Меркурий', $system);
    }

    public function testAWorkerAskingForTheProjectGetsTheBlockOfTheOrchestratorsChat(): void {
        // spec: brief/orchestration.md#права-воркера-задаёт-оркестратор — the same block the orchestrator's prompt has
        $stack = new AgentStack(new ScriptedLlm([ScriptedLlm::text('ок')]));
        $project = $this->projects->create('Меркурий', 'Агент на сервере');
        $this->projects->activate('telegram', '42', $project->id);
        $stack->scheduler->useProjects(new ProjectContext($this->projects));
        $stack->say('привет');
        $stack->scheduler->awaitIdle();
        $run = $stack->runs->find(1);
        self::assertNotNull($run);
        $parent = new ToolContext($run->id, $run->conversationId, $this->owner, new NullCancellation());

        $context = $stack->scheduler->workerContext($parent, ['project']);

        self::assertStringContainsString('<untrusted-data source="project">', $context);
        self::assertStringContainsString('Активный проект: Меркурий', $context);
        self::assertSame('', $stack->scheduler->workerContext($parent, []), 'isolation unless asked');
        self::assertSame('', $stack->scheduler->workerContext($parent, ['participant']), 'no core memory without the memory module');
    }
}
