<?php

declare(strict_types=1);

namespace OpenCCK\Test\Unit\App\Job;

use Amp\PHPUnit\AsyncTestCase;
use DateTimeImmutable;
use DateTimeZone;
use OpenCCK\App\Job\Initiative;
use OpenCCK\App\Job\JobScheduler;
use OpenCCK\App\Job\ProactiveBudget;
use OpenCCK\Domain\Entity\Member;
use OpenCCK\Domain\Job\Job;
use OpenCCK\Test\Support\AgentStack;
use OpenCCK\Test\Support\InMemoryJobRepository;
use OpenCCK\Test\Support\ScriptedLlm;
use Psr\Log\NullLogger;

/**
 * Messages the agent starts itself: a built-in job looks around on its schedule and writes only when there is
 * something to say; all reports the owner did not ask for right now share a daily cap, the rest waits for the next day.
 */
final class InitiativeTest extends AsyncTestCase {
    private DateTimeImmutable $now;
    private InMemoryJobRepository $jobs;
    private AgentStack $stack;
    private Member $owner;

    protected function setUp(): void {
        parent::setUp();
        $this->now = new DateTimeImmutable('2026-11-17 10:00:00 UTC');
        $this->jobs = new InMemoryJobRepository();
    }

    private function scheduler(ScriptedLlm $llm, int $perDay): JobScheduler {
        $this->stack = new AgentStack($llm);
        $this->owner = $this->stack->principals->ensureOwner('telegram', '42', 'Owner');
        $zone = new DateTimeZone('UTC');
        return new JobScheduler(
            $this->jobs, $this->stack->loop, $this->stack->runs, $this->stack->transcript, $this->stack->conversations,
            $this->stack->principals, $this->stack->policies, $this->stack->registry, $this->stack->channel, 'test/model',
            $zone, null, new NullLogger(), fn(): DateTimeImmutable => $this->now,
            budget: new ProactiveBudget($perDay, $zone, fn(): DateTimeImmutable => $this->now),
        );
    }

    public function testReportsOverTheDailyCapWaitForTheNextDay(): void {
        $scheduler = $this->scheduler(new ScriptedLlm([ScriptedLlm::text('Отчёт 1'), ScriptedLlm::text('Отчёт 2'), ScriptedLlm::text('Отчёт 3')]), 2);
        foreach ([1, 2, 3] as $n) {
            $this->jobs->create($this->owner->id, 'telegram', '42', 'Задание ' . $n, 'Проверь ' . $n, 'daily:09:00', $this->now->modify('-1 minute'));
        }

        $scheduler->tick(true);

        self::assertCount(2, $this->stack->channel->texts('send'), 'two a day');
        self::assertCount(1, $this->jobs->pendingReports(), 'the third waits');

        $this->now = $this->now->modify('+1 hour');
        $scheduler->tick(true);
        self::assertCount(2, $this->stack->channel->texts('send'), 'still the same day');

        $this->now = new DateTimeImmutable('2026-11-18 00:05:00 UTC');
        $scheduler->tick(true);
        self::assertCount(3, $this->stack->channel->texts('send'), 'the next day it comes');
        self::assertStringContainsString('Отчёт 3', implode("\n", $this->stack->channel->texts('send')));
    }

    public function testNoCapWhenZero(): void {
        $scheduler = $this->scheduler(new ScriptedLlm([ScriptedLlm::text('А'), ScriptedLlm::text('Б')]), 0);
        foreach ([1, 2] as $n) {
            $this->jobs->create($this->owner->id, 'telegram', '42', 'З' . $n, 'П' . $n, 'daily:23:00', $this->now->modify('-1 minute'));
        }

        $scheduler->tick(true);

        self::assertCount(2, $this->stack->channel->texts('send'));
    }

    public function testTheInitiativeJobFollowsTheSetting(): void {
        $this->scheduler(new ScriptedLlm([]), 3);
        $initiative = new Initiative($this->jobs, new DateTimeZone('UTC'), fn(): DateTimeImmutable => $this->now);

        $initiative->sync($this->owner, 'telegram', '42', 'daily:10:30');
        $jobs = $this->jobs->list($this->owner->id);
        self::assertCount(1, $jobs);
        self::assertSame(Initiative::TITLE, $jobs[0]->title);
        self::assertSame('daily:10:30', $jobs[0]->schedule);
        self::assertSame(Job::ACTIVE, $jobs[0]->status);
        self::assertStringContainsString('[SILENT]', $jobs[0]->prompt, 'nothing to say — nothing is sent');

        $initiative->sync($this->owner, 'telegram', '42', 'daily:10:30');
        self::assertCount(1, $this->jobs->list($this->owner->id), 'the same setting changes nothing');

        $initiative->sync($this->owner, 'telegram', '42', 'every:240');
        $active = array_values(array_filter($this->jobs->list($this->owner->id), static fn(Job $j): bool => $j->status === Job::ACTIVE));
        self::assertCount(1, $active);
        self::assertSame('every:240', $active[0]->schedule);

        $initiative->sync($this->owner, 'telegram', '42', '');
        self::assertSame([], array_values(array_filter($this->jobs->list($this->owner->id), static fn(Job $j): bool => $j->status === Job::ACTIVE)), 'an empty setting turns it off');
    }

    public function testABadScheduleIsReportedAndChangesNothing(): void {
        $this->scheduler(new ScriptedLlm([]), 3);
        $initiative = new Initiative($this->jobs, new DateTimeZone('UTC'), fn(): DateTimeImmutable => $this->now);

        self::assertFalse($initiative->sync($this->owner, 'telegram', '42', 'every:1'));
        self::assertSame([], $this->jobs->list($this->owner->id));
    }
}
