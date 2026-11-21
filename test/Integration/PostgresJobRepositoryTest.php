<?php

declare(strict_types=1);

namespace OpenCCK\Test\Integration;

use Amp\PHPUnit\AsyncTestCase;
use Amp\Postgres\PostgresConnection;
use DateTimeImmutable;
use OpenCCK\Domain\Job\Job;
use OpenCCK\Domain\Job\Schedule;
use OpenCCK\Infrastructure\Repository\PostgresJobRepository;
use OpenCCK\Test\Support\TestDatabase;

/**
 * @group integration
 */
final class PostgresJobRepositoryTest extends AsyncTestCase {
    private PostgresConnection $db;
    private PostgresJobRepository $jobs;
    private int $principalId;

    protected function setUp(): void {
        parent::setUp();
        $this->db = TestDatabase::connect();
        [$this->principalId] = TestDatabase::conversation($this->db);
        $this->jobs = new PostgresJobRepository($this->db);
    }

    protected function tearDown(): void {
        $this->db->close();
        parent::tearDown();
    }

    public function testAJobIsClaimedOnceAndAOneOffEndsAfterItsRun(): void {
        $now = new DateTimeImmutable('2026-11-17 10:00 UTC');
        $every = $this->jobs->create($this->principalId, 'telegram', '42', 'Диск', 'Проверь диск', 'every:60', $now->modify('-1 minute'));
        $once = $this->jobs->create($this->principalId, 'telegram', '42', 'Вода', 'Напомни', 'once:2026-11-17T09:59:00Z', $now->modify('-1 minute'));

        self::assertSame([$every->id, $once->id], array_map(static fn(Job $j): int => $j->id, $this->jobs->due($now, 5)));
        self::assertTrue($this->jobs->claim($every->id, $now, $now->modify('+60 minutes')));
        self::assertFalse($this->jobs->claim($every->id, $now, $now->modify('+60 minutes')), 'a claimed job is not due');
        self::assertTrue($this->jobs->claim($once->id, $now, null));

        $this->jobs->finish($every->id, 'занято 41%', null);
        $this->jobs->finish($once->id, 'Выпейте воды', 'отчёт ночью');

        $after = $this->jobs->find($every->id);
        self::assertSame([1, 'занято 41%', Job::ACTIVE], [$after?->runs, $after?->lastResult, $after?->status]);
        self::assertEquals($now->modify('+60 minutes'), $after?->nextRunAt);
        self::assertSame(Job::DONE, $this->jobs->find($once->id)?->status);
        self::assertSame([$once->id], array_map(static fn(Job $j): int => $j->id, $this->jobs->pendingReports()));
        $this->jobs->clearPending($once->id);
        self::assertSame([], $this->jobs->pendingReports());
    }

    public function testAJobByEventTakesEachDeliveryOnceAndStaysActive(): void {
        $job = $this->jobs->create($this->principalId, 'telegram', '42', 'PR', 'Разбери', Schedule::EVENT, null);
        $timed = $this->jobs->create($this->principalId, 'telegram', '42', 'Время', 'x', 'every:60', new DateTimeImmutable());

        self::assertFalse($this->jobs->bindHook($timed->id, 'gh-hook'), 'only a job by event takes a webhook');
        self::assertTrue($this->jobs->bindHook($job->id, 'gh-hook'));
        self::assertSame('gh-hook', $this->jobs->find($job->id)?->hookSecret);
        self::assertSame([], array_filter($this->jobs->due(new DateTimeImmutable('+1 year'), 5), static fn(Job $j): bool => $j->id === $job->id), 'never due by time');

        self::assertTrue($this->jobs->claimEvent($job->id));
        self::assertFalse($this->jobs->claimEvent($job->id), 'running');
        self::assertTrue($this->jobs->recordDelivery($job->id, 'd1'));
        self::assertFalse($this->jobs->recordDelivery($job->id, 'd1'));
        self::assertSame(1, $this->jobs->deliveriesSince($job->id, new DateTimeImmutable('-1 hour')));
        $this->jobs->finish($job->id, 'разобран', null);
        self::assertSame([Job::ACTIVE, 1], [$this->jobs->find($job->id)?->status, $this->jobs->find($job->id)?->runs]);

        self::assertTrue($this->jobs->claimEvent($job->id));
        $this->jobs->release($job->id);
        self::assertSame(1, $this->jobs->find($job->id)?->runs, 'a released claim does not count');
        self::assertTrue($this->jobs->bindHook($job->id, null));
        self::assertNull($this->jobs->find($job->id)?->hookSecret);
    }

    public function testAJobKeepsItsOriginAndTheTrustOfTheOwner(): void {
        $job = $this->jobs->create($this->principalId, 'telegram', '42', 'Цена', 'Сообщи цену', 'every:60', new DateTimeImmutable(), 'http_request');
        self::assertSame('http_request', $job->origin);
        self::assertFalse($job->trusted);

        self::assertTrue($this->jobs->setTrusted($job->id, true));
        self::assertTrue($this->jobs->find($job->id)?->trusted);
        self::assertNull($this->jobs->create($this->principalId, 'telegram', '42', 'Сводка', 'Пришли', 'every:60', null)->origin);
    }

    public function testThePlaceOfTheReportIsKeptAndResetToTheDefault(): void {
        // spec: brief/scheduler.md#место-итога-задания
        $job = $this->jobs->create($this->principalId, 'telegram', '-100500', 'Курс', 'Узнай', 'every:60', null, threadId: '577');
        self::assertSame(Job::DELIVER_DEFAULT, $job->deliver);

        self::assertTrue($this->jobs->setDelivery($job->id, Job::DELIVER_CHAT, '-100600', '12'));
        $found = $this->jobs->find($job->id);
        self::assertSame([Job::DELIVER_CHAT, '-100600', '12'], [$found?->deliver, $found?->deliverChat, $found?->deliverThread]);
        $this->jobs->setDelivery($job->id, Job::DELIVER_DEFAULT, '', '');
        self::assertSame(Job::DELIVER_DEFAULT, $this->jobs->find($job->id)?->deliver);
    }

}
