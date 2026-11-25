<?php

declare(strict_types=1);

namespace OpenCCK\Test\Unit\App\Job;

use Amp\NullCancellation;
use Amp\PHPUnit\AsyncTestCase;
use DateTimeImmutable;
use DateTimeZone;
use OpenCCK\App\Agent\Tool\Memory\MyPreferencesTool;
use OpenCCK\App\Agent\Tool\ToolContext;
use OpenCCK\App\Job\JobScheduler;
use OpenCCK\App\Job\ParticipantClock;
use OpenCCK\Domain\Entity\Member;
use OpenCCK\Domain\Entity\MemberKind;
use OpenCCK\Domain\Entity\Role;
use OpenCCK\Test\Support\AgentStack;
use OpenCCK\Test\Support\InMemoryJobRepository;
use OpenCCK\Test\Support\InMemoryParticipantPrefRepository;
use OpenCCK\Test\Support\ScriptedLlm;
use Psr\Log\NullLogger;

/**
 * Each participant has their own time zone and quiet hours: their jobs run by their clock and their reports wait for
 * the end of their own night; without settings of their own, the owner's from .env apply.
 *
 * spec: brief/participants.md#несколько-пользователей
 */
final class ParticipantClockTest extends AsyncTestCase {
    public function testAParticipantSetsTheirZoneAndQuietHours(): void {
        $prefs = new InMemoryParticipantPrefRepository();
        $clock = new ParticipantClock($prefs, new DateTimeZone('+03:00'), [23, 8]);
        $tool = new MyPreferencesTool($prefs, $clock);
        $user = new Member(5, MemberKind::Human, Role::user(), 'Вася');
        $context = new ToolContext(1, 1, $user, new NullCancellation());

        self::assertSame('+03:00', $clock->zone(5)->getName(), 'the owner\'s zone by default');
        self::assertSame([23, 8], $clock->quiet(5));

        self::assertFalse($tool->execute(['timezone' => 'Asia/Bangkok', 'quiet_hours' => '22-7'], $context)->isError);
        self::assertSame('Asia/Bangkok', $clock->zone(5)->getName());
        self::assertSame([22, 7], $clock->quiet(5));
        self::assertSame('+03:00', $clock->zone(1)->getName(), 'others keep theirs');

        self::assertFalse($tool->execute(['quiet_hours' => 'none'], $context)->isError);
        self::assertNull($clock->quiet(5), 'no quiet hours at all');
        self::assertFalse($tool->execute(['quiet_hours' => 'default', 'timezone' => 'default'], $context)->isError);
        self::assertSame([23, 8], $clock->quiet(5));
        self::assertSame('+03:00', $clock->zone(5)->getName());

        self::assertTrue($tool->execute(['timezone' => 'Mars/Olympus'], $context)->isError);
        self::assertTrue($tool->execute(['quiet_hours' => '25-3'], $context)->isError);
        self::assertStringContainsString('+03:00', $tool->execute([], $context)->content);
    }

    public function testAJobOfAParticipantRunsAndReportsByTheirClock(): void {
        $prefs = new InMemoryParticipantPrefRepository();
        $now = new DateTimeImmutable('2026-11-18 16:00:00 UTC'); // 19:00 in +03:00, 23:00 in Bangkok
        $stack = new AgentStack(new ScriptedLlm([ScriptedLlm::text('Отчёт пользователя')]));
        $owner = $stack->principals->ensureOwner('telegram', '42', 'Owner');
        $user = $stack->principals->createVisitor('telegram', '77', 'Вася');
        $stack->principals->decideVisitor($user->id, Role::user(), \OpenCCK\Domain\Entity\MemberStatus::Active);
        $prefs->save($user->id, 'Asia/Bangkok', '22-7');
        $jobs = new InMemoryJobRepository();
        $clock = new ParticipantClock($prefs, new DateTimeZone('+03:00'), [23, 8]);
        $scheduler = new JobScheduler($jobs, $stack->loop, $stack->runs, $stack->transcript, $stack->conversations, $stack->principals, $stack->policies, $stack->registry, $stack->channel, 'test/model', new DateTimeZone('+03:00'), [23, 8], new NullLogger(), static fn(): DateTimeImmutable => $now, clock: $clock);
        $jobs->create($user->id, 'telegram', '77', 'Сводка', 'Сделай сводку', 'daily:09:00', $now->modify('-1 minute'));

        self::assertSame('Asia/Bangkok', $scheduler->zone($user->id)->getName());
        self::assertTrue($scheduler->isQuiet($now, $user->id), '23:00 in Bangkok is his night');
        self::assertFalse($scheduler->isQuiet($now, $owner->id), '19:00 for the owner');

        $scheduler->tick(true);

        self::assertSame([], $stack->channel->texts('send'), 'the report waits for his morning');
        $next = $jobs->list($user->id)[0]->nextRunAt;
        self::assertSame('09:00', $next?->setTimezone(new DateTimeZone('Asia/Bangkok'))->format('H:i'), 'the next run is 09:00 of his zone');
    }
}
