<?php

declare(strict_types=1);

namespace OpenCCK\Test\Unit\App\Job;

use Amp\NullCancellation;
use Amp\PHPUnit\AsyncTestCase;
use DateTimeImmutable;
use DateTimeZone;
use Mercurius\Memory\Domain\ContentScreen;
use OpenCCK\App\Agent\Tool\Projects\JobCreateTool;
use OpenCCK\App\Agent\Tool\Projects\JobManageTool;
use OpenCCK\App\Agent\Tool\ToolContext;
use OpenCCK\App\Job\JobScheduler;
use OpenCCK\Domain\Agent\DangerClass;
use OpenCCK\Domain\Entity\Principal;
use OpenCCK\Domain\Entity\Role;
use OpenCCK\Domain\Project\Project;
use OpenCCK\Test\Support\AgentStack;
use OpenCCK\Test\Support\InMemoryJobRepository;
use OpenCCK\Test\Support\InMemoryProjectRepository;
use OpenCCK\Test\Support\ScriptedLlm;
use Psr\Log\NullLogger;

/**
 * spec: brief/scheduler.md#задания-проекта
 */
final class ProjectJobsTest extends AsyncTestCase {
    private DateTimeImmutable $now;
    private InMemoryJobRepository $jobs;
    private InMemoryProjectRepository $projects;
    private AgentStack $stack;
    private Principal $owner;
    private Principal $agent;
    /** @var list<array{int, string}> who was told what in their private place */
    private array $told = [];

    protected function setUp(): void {
        parent::setUp();
        $this->now = new DateTimeImmutable('2026-11-17 10:00:00 UTC');
        $this->jobs = new InMemoryJobRepository();
        $this->projects = new InMemoryProjectRepository();
    }

    private function scheduler(ScriptedLlm $llm): JobScheduler {
        $this->stack = new AgentStack($llm);
        $this->owner = $this->stack->principals->ensureOwner('telegram', '42', 'Owner');
        $this->agent = $this->stack->principals->createAgent('helper', Role::admin());
        $scheduler = new JobScheduler(
            $this->jobs,
            $this->stack->loop,
            $this->stack->runs,
            $this->stack->transcript,
            $this->stack->conversations,
            $this->stack->principals,
            $this->stack->policies,
            $this->stack->registry,
            $this->stack->channel,
            'test/model',
            new DateTimeZone('UTC'),
            null,
            new NullLogger(),
            fn(): DateTimeImmutable => $this->now,
        );
        $scheduler->useProjects($this->projects);
        $scheduler->useInbox(function (Principal $to, string $text): bool {
            $this->told[] = [$to->id, $text];
            return true;
        });
        return $scheduler;
    }

    private function as(Principal $who, string $channel = 'a2a'): ToolContext {
        return new ToolContext($who->id, $this->stack->conversations->openConversation($who->id, $channel, 'chat-' . $who->id), $who, new NullCancellation());
    }

    private function create(JobScheduler $scheduler): JobCreateTool {
        return new JobCreateTool($this->jobs, $this->stack->conversations, $scheduler, new ContentScreen(), null, $this->projects);
    }

    public function testAJobOfAProjectRunsInTheNameOfItsOwnerAndReportsToThem(): void {
        $scheduler = $this->scheduler(new ScriptedLlm([ScriptedLlm::text('Откликов: 3.')]));
        $project = $this->projects->create('Поиск', 'работа');
        $job = $this->jobs->create($this->agent->id, 'a2a', 'a2a-ctx', 'Отклики', 'Сделай отклики', 'every:60', $this->now->modify('-1 minute'));
        $this->jobs->setProject($job->id, $project->id);

        $scheduler->tick(true);

        $run = $this->stack->runs->find(1);
        self::assertSame($this->owner->id, $run?->principalId, 'the project is the owner\'s: the run is theirs, with their sandbox and browser profile');
        self::assertStringContainsString('Поиск', $this->stack->llm->requests[0]->messages[0]->content ?? '', 'the run knows its project');
        self::assertNull($this->projects->active(JobScheduler::CHANNEL, 'job-1-1'), 'the run is unbound from the project when it ends');
        self::assertSame($this->owner->id, $this->told[0][0], 'no topic: the report goes to the owner of the project');
        self::assertStringContainsString('Откликов: 3.', $this->told[0][1]);
        self::assertSame([], $this->stack->channel->texts('send'), 'not into the chat of whoever set the job');
    }

    public function testAJobOfAProjectGetsTheProjectsMemoryFile(): void {
        // spec: brief/memory.md#файл-памяти-места — a job of a project gets the project's MEMORY.md as a chat of the project does
        $scheduler = $this->scheduler(new ScriptedLlm([ScriptedLlm::text('Готово.')]));
        $project = $this->projects->create('Поиск', 'работа');
        $seen = [];
        $scheduler->usePlaceMemory(function (Principal $who, string $channel, string $chat) use (&$seen): array {
            $seen[] = [$channel, $this->projects->active($channel, $chat)?->name];
            return ['ПРАВИЛА ПРОЕКТА: откликаться только на удалёнку', 'ЗАМЕТКИ ПРОЕКТА'];
        });
        $job = $this->jobs->create($this->agent->id, 'a2a', 'a2a-ctx', 'Отклики', 'Сделай отклики', 'every:60', $this->now->modify('-1 minute'));
        $this->jobs->setProject($job->id, $project->id);

        $scheduler->tick(true);

        $system = $this->stack->llm->requests[0]->messages[0]->content ?? '';
        self::assertSame([[JobScheduler::CHANNEL, 'Поиск']], $seen, 'read while the run is bound to the project');
        self::assertStringContainsString('ПРАВИЛА ПРОЕКТА: откликаться только на удалёнку', $system);
        self::assertStringContainsString('ЗАМЕТКИ ПРОЕКТА', $system);
    }

    public function testAJobOfAProjectReportsIntoTheTopicOfTheProject(): void {
        $scheduler = $this->scheduler(new ScriptedLlm([ScriptedLlm::text('Готово.')]));
        $project = $this->projects->create('Поиск', 'работа');
        $this->projects->activate('telegram', '-100500', $project->id, '4');
        $job = $this->jobs->create($this->agent->id, 'a2a', 'a2a-ctx', 'Отклики', 'Сделай отклики', 'every:60', $this->now->modify('-1 minute'));
        $this->jobs->setProject($job->id, $project->id);

        $scheduler->tick(true);

        self::assertContains('4', $this->stack->channel->threads);
        self::assertStringContainsString('Готово.', implode("\n", $this->stack->channel->texts('send')));
        // spec: brief/scheduler.md#задания-проекта — the agent that set the job hears its outcome too: it is not in the topic
        self::assertSame([$this->agent->id], array_map(static fn(array $told): int => $told[0], $this->told));
        self::assertStringContainsString('Готово.', $this->told[0][1]);
    }

    public function testAJobAnAgentSetForItselfReportsToThatAgent(): void {
        $scheduler = $this->scheduler(new ScriptedLlm([ScriptedLlm::text('Сделано.')]));
        $this->jobs->create($this->agent->id, 'a2a', 'a2a-ctx', 'Проверка', 'Проверь', 'every:60', $this->now->modify('-1 minute'));

        $scheduler->tick(true);

        self::assertSame($this->agent->id, $this->told[0][0], 'a chat of the network of agents is not a chat of the channel: the agent is told over the network');
        self::assertSame([], $this->stack->channel->texts('send'));
    }

    public function testAMemberSetsAJobOfAProjectOnlyByTheOwnersCard(): void {
        $scheduler = $this->scheduler(new ScriptedLlm([]));
        $project = $this->projects->create('Поиск', 'работа');
        $this->projects->share($project->id, $this->agent->id, 'write', null);
        $tool = $this->create($scheduler);
        $args = ['title' => 'Отклики', 'prompt' => 'Сделай отклики', 'schedule' => 'every:60', 'project' => 'Поиск'];

        $prepared = $tool->prepare($args, $this->agent, $this->stack->policies->forPrincipal($this->agent), new NullCancellation());
        self::assertIsArray($prepared);
        self::assertSame(DangerClass::Irreversible, $tool->danger($prepared), 'the job will run in the name of the project\'s owner: the owner of the agent sees it first');
        self::assertSame('job-create:project:' . $project->id, $tool->grantKey($prepared));

        self::assertFalse($tool->execute($prepared, $this->as($this->agent))->isError);
        $job = $this->jobs->find(1) ?? self::fail('no job');
        self::assertSame($project->id, $job->projectId);
        self::assertTrue($job->trusted, 'the owner saw the job on the card: saved grants apply in its runs');

        $own = $tool->prepare($args, $this->owner, $this->stack->policies->forPrincipal($this->owner), new NullCancellation());
        self::assertIsArray($own);
        self::assertSame(DangerClass::Write, $tool->danger($own), 'the owner of the project sets its jobs without a card');
    }

    public function testAJobOfAProjectNeedsTheWriteLevel(): void {
        $scheduler = $this->scheduler(new ScriptedLlm([]));
        $project = $this->projects->create('Поиск', 'работа');
        $tool = $this->create($scheduler);
        $args = ['title' => 'Отклики', 'prompt' => 'Сделай отклики', 'schedule' => 'every:60', 'project' => 'Поиск'];

        self::assertIsString($tool->prepare($args, $this->agent, $this->stack->policies->forPrincipal($this->agent), new NullCancellation()), 'a stranger to the project');
        $this->projects->share($project->id, $this->agent->id, 'read', null);
        self::assertIsString($tool->prepare($args, $this->agent, $this->stack->policies->forPrincipal($this->agent), new NullCancellation()), 'read does not set jobs');
    }

    public function testTheOwnerOfAProjectSeesItsJobsAndPutsAJobIntoIt(): void {
        $scheduler = $this->scheduler(new ScriptedLlm([]));
        $project = $this->projects->create('Поиск', 'работа');
        $job = $this->jobs->create($this->agent->id, 'a2a', 'a2a-ctx', 'Отклики', 'Сделай отклики', 'every:60', $this->now->modify('+1 hour'));
        $manage = new JobManageTool($this->jobs, $scheduler, $this->projects);

        self::assertFalse($manage->execute(['action' => 'to_project', 'id' => $job->id, 'project' => 'Поиск'], $this->as($this->owner, 'telegram'))->isError);
        self::assertSame($project->id, $this->jobs->find($job->id)?->projectId);

        $member = $this->stack->principals->createAgent('second', Role::user());
        self::assertStringNotContainsString('Отклики', $manage->execute(['action' => 'list'], $this->as($member))->content);
        $this->projects->share($project->id, $member->id, 'write', null);
        self::assertStringContainsString('Отклики', $manage->execute(['action' => 'list'], $this->as($member))->content, 'a member sees the jobs of the project');
        self::assertFalse($manage->execute(['action' => 'pause', 'id' => $job->id], $this->as($member))->isError, 'and with the write level manages them');

        self::assertTrue($manage->execute(['action' => 'to_project', 'id' => $job->id, 'project' => 'Поиск'], $this->as($member))->isError, 'only the owner of a project puts a job into it');
        self::assertInstanceOf(Project::class, $project);
    }

    public function testAFileSentFromAJobRunGoesWhereTheReportGoes(): void {
        // spec: brief/scheduler.md#задания-проекта — the conversation of a run is not a chat of the messenger: what the
        //       run sends "here" goes to the topic of the project, else to the chat the job was set in
        $scheduler = $this->scheduler(new ScriptedLlm([]));
        $project = $this->projects->create('Новости', 'дайджест');
        $this->projects->activate('telegram', '-100500', $project->id, '4');
        $inTopic = $this->jobs->create($this->owner->id, 'telegram', '42', 'Дайджест', 'Собери', 'every:60', $this->now->modify('+1 hour'));
        $this->jobs->setProject($inTopic->id, $project->id);
        $plain = $this->jobs->create($this->owner->id, 'telegram', '42', 'Диск', 'Проверь', 'every:60', $this->now->modify('+1 hour'), null, '7');

        self::assertSame(['-100500', '4'], $scheduler->homeOf(sprintf('job-%d-3', $inTopic->id)));
        self::assertSame(['42', '7'], $scheduler->homeOf(sprintf('job-%d-1', $plain->id)));
        self::assertNull($scheduler->homeOf('job-999-1'));

        $recipients = new \OpenCCK\App\Agent\Tool\Messaging\Recipients($this->stack->principals, 'telegram');
        $recipients->useHome(static fn(\OpenCCK\Domain\Agent\ConversationMeta $meta): ?array => $meta->channel === JobScheduler::CHANNEL ? $scheduler->homeOf($meta->chatId) : null);
        $conversation = $this->stack->conversations->openConversation($this->owner->id, JobScheduler::CHANNEL, sprintf('job-%d-3', $inTopic->id));
        $context = new ToolContext(1, $conversation, $this->owner, new NullCancellation());
        self::assertSame(['-100500', '4'], $recipients->where(null, $context, $this->stack->conversations));
    }

    public function testTheProjectShowsItsJobsAndItsFolder(): void {
        // spec: brief/scheduler.md#задания-проекта — asked about "the task that collects news", the agent finds the job in
        //       the project instead of setting a second one
        $scheduler = $this->scheduler(new ScriptedLlm([]));
        $project = $this->projects->create('Новости', 'дайджест');
        $this->projects->activate('telegram', '-100500', $project->id, '4');
        $job = $this->jobs->create($this->owner->id, 'telegram', '42', 'Утренний дайджест', 'Собери', 'daily:10:00', $this->now->modify('+1 hour'));
        $this->jobs->setProject($job->id, $project->id);
        $context = new \OpenCCK\App\Agent\ProjectContext($this->projects);
        $context->useJobs(fn(int $id): array => array_map(static fn(\OpenCCK\Domain\Job\Job $j): string => JobManageTool::line($j, $scheduler), $this->jobs->ofProjects([$id])));

        $block = $context->block($this->owner, 'telegram', '-100500', '4');
        self::assertStringContainsString('Утренний дайджест', $block);
        self::assertStringContainsString('job_manage', $block);
        self::assertStringContainsString(sprintf('projects/%d/', $project->id), $block);

        $tool = new \OpenCCK\App\Agent\Tool\Projects\ProjectTool($this->projects, $this->stack->conversations, $context);
        $shown = $tool->execute(['name' => 'Новости'], $this->as($this->owner, 'telegram'))->content;
        self::assertStringContainsString('Утренний дайджест', $shown);
        self::assertStringContainsString(sprintf('projects/%d/', $project->id), $shown);
    }

    public function testAnEditNamingAProjectMovesTheJobThereAndSaysSo(): void {
        // spec: brief/scheduler.md#как-сделано-задания-и-доставка-итогов — edit with project was silently ignored, the agent
        //       told the owner the job had moved
        $scheduler = $this->scheduler(new ScriptedLlm([]));
        $project = $this->projects->create('Новости', 'дайджест');
        $job = $this->jobs->create($this->owner->id, 'telegram', '42', 'Дайджест', 'Собери новости', 'daily:05:00', $this->now->modify('+1 hour'));
        $manage = new JobManageTool($this->jobs, $scheduler, $this->projects, new \Mercurius\Memory\Domain\ContentScreen());

        $edited = $manage->execute(['action' => 'edit', 'id' => $job->id, 'prompt' => 'Собери новости без источников', 'project' => 'Новости'], $this->as($this->owner, 'telegram'));

        self::assertFalse($edited->isError, $edited->content);
        self::assertSame($project->id, $this->jobs->find($job->id)?->projectId);
        self::assertStringContainsString('«Новости»', $edited->content, 'the answer names the project');
        self::assertTrue($manage->execute(['action' => 'edit', 'id' => $job->id, 'project' => 'Нет такого'], $this->as($this->owner, 'telegram'))->isError, 'a project that cannot be is an error, not silence');
    }
}
