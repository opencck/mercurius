<?php

declare(strict_types=1);

namespace OpenCCK\Test\Unit\App\Service;

use Amp\NullCancellation;
use Amp\PHPUnit\AsyncTestCase;
use DateTimeImmutable;
use DateTimeZone;
use OpenCCK\App\Agent\Tool\Projects\JobManageTool;
use OpenCCK\App\Agent\Tool\ToolContext;
use OpenCCK\App\Job\JobScheduler;
use OpenCCK\App\Service\OwnerOverview;
use OpenCCK\Domain\Entity\MemberStatus;
use OpenCCK\Domain\Entity\Role;
use OpenCCK\Domain\Job\Job;
use OpenCCK\Test\Support\AgentStack;
use OpenCCK\Test\Support\InMemoryJobRepository;
use OpenCCK\Test\Support\InMemoryProjectRepository;
use OpenCCK\Test\Support\ScriptedLlm;
use Psr\Log\NullLogger;

/**
 * The owner sees a participant and their projects in one message and changes what is there from their own chat.
 *
 * spec: brief/participants.md#обзор-владельца
 */
final class OwnerOverviewTest extends AsyncTestCase {
    public function testTheOwnerFindsAndCancelsAJobOfSomeoneElsesProjectWithoutGoingIntoItsChat(): void {
        $stack = new AgentStack(new ScriptedLlm([]));
        $owner = $stack->principals->ensureOwner('telegram', '42', 'Owner');
        $anna = $stack->principals->decideVisitor($stack->principals->createVisitor('telegram', '7', 'Анна')->id, Role::user(), MemberStatus::Active);
        self::assertNotNull($anna);
        $projects = new InMemoryProjectRepository();
        $project = $projects->create('Сайт', '', $anna->id);
        $jobs = new InMemoryJobRepository();
        $job = $jobs->create($anna->id, 'telegram', '-100500', 'Проверка сайта', 'Проверь сайт', 'every:60', new DateTimeImmutable('+1 hour'), threadId: '4');
        $jobs->setProject($job->id, $project->id);
        $scheduler = new JobScheduler($jobs, $stack->loop, $stack->runs, $stack->transcript, $stack->conversations, $stack->principals, $stack->policies, $stack->registry, $stack->channel, 'test/model', new DateTimeZone('UTC'), null, new NullLogger());
        $overview = new OwnerOverview($stack->principals, $projects, $jobs, $stack->policies, $stack->approvals, $scheduler);

        self::assertStringContainsString('Анна', $overview->show($owner, ''));
        $shown = $overview->show($owner, 'Анна');
        self::assertStringContainsString('Проект «Сайт»', $shown);
        self::assertStringContainsString('#' . $job->id . ' «Проверка сайта»', $shown, 'the job of her project, with its number');
        self::assertStringContainsString('job_manage', $shown, 'and how to change it');
        self::assertStringContainsString('только для владельца', $overview->show($anna, 'Owner'));

        $manage = new JobManageTool($jobs, $scheduler, $projects);
        $private = $stack->conversations->openConversation($owner->id, 'telegram', '42');
        $cancelled = $manage->execute(['action' => 'cancel', 'id' => $job->id], new ToolContext(1, $private, $owner, new NullCancellation()));

        self::assertFalse($cancelled->isError, $cancelled->content);
        self::assertSame(Job::DONE, $jobs->find($job->id)?->status, 'cancelled from the owner\'s private chat');
    }
}
