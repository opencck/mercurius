<?php

declare(strict_types=1);

namespace OpenCCK\Test\Unit\App\Agent\Tool;

use Amp\NullCancellation;
use Amp\PHPUnit\AsyncTestCase;
use OpenCCK\App\Agent\Tool\SpendingTool;
use OpenCCK\App\Agent\Tool\ToolContext;
use OpenCCK\App\Service\TraceReports;
use OpenCCK\Domain\Agent\RunLimits;
use OpenCCK\Domain\Entity\PrincipalStatus;
use OpenCCK\Domain\Entity\Role;
use OpenCCK\Test\Support\FakeTraceReports;
use OpenCCK\Test\Support\InMemoryPrincipalRepository;
use OpenCCK\Test\Support\InMemoryRunRepository;
use OpenCCK\Test\Support\InMemoryTraceRepository;

/**
 * The agent answers "how many tokens did this take" from the run's own records, not a guess.
 */
final class SpendingToolTest extends AsyncTestCase {
    public function testARunIsFoundByTheMessageItWrote(): void {
        $principals = new InMemoryPrincipalRepository();
        $owner = $principals->ensureOwner('telegram', '42', 'Owner');
        $runs = new InMemoryRunRepository();
        $limits = new RunLimits(30, 100_000, 900, 120);
        $old = $runs->start(1, $owner->id, 'm', $limits);
        self::assertNotNull($old);
        $runs->settle($old->id, 0, 10_071, 0);
        $runs->finish($old->id, \OpenCCK\Domain\Agent\RunStatus::Done, \OpenCCK\Domain\Agent\StopReason::Answered);
        $now = $runs->start(1, $owner->id, 'm', $limits);
        self::assertNotNull($now);
        $conversations = new \OpenCCK\Test\Support\InMemoryConversationRepository();
        $conversation = $conversations->openConversation($owner->id, 'telegram', '42');
        $messages = new \OpenCCK\Test\Support\InMemoryRunMessageRepository();
        $messages->record($old->id, 'telegram', '42', '295');
        $tool = new SpendingTool(new TraceReports(new FakeTraceReports(), new InMemoryTraceRepository()), $runs, $messages, $conversations);
        $context = new ToolContext($now->id, $conversation, $owner, new NullCancellation());

        $found = $tool->execute(['message_id' => 295], $context);
        self::assertStringContainsString('Сообщение #295 написал запуск #' . $old->id, $found->content);
        self::assertTrue($tool->execute(['message_id' => 296], $context)->isError, 'an unknown message is not guessed');
    }

    public function testTheCurrentRunReportsWhatItHasSpentSoFar(): void {
        $principals = new InMemoryPrincipalRepository();
        $owner = $principals->ensureOwner('telegram', '42', 'Owner');
        $visitor = $principals->createVisitor('telegram', '7', 'Гость');
        $principals->decideVisitor($visitor->id, Role::User, PrincipalStatus::Active);
        $user = $principals->find($visitor->id);
        self::assertNotNull($user);
        $runs = new InMemoryRunRepository();
        $limits = new RunLimits(30, 100_000, 900, 120);
        $mine = $runs->start(1, $owner->id, 'm', $limits);
        $theirs = $runs->start(2, $user->id, 'm', $limits);
        self::assertNotNull($mine);
        self::assertNotNull($theirs);
        $runs->settle($mine->id, 0, 3391, 0);
        $runs->settle($mine->id, 0, 1200, 0);
        $tool = new SpendingTool(new TraceReports(new FakeTraceReports(), new InMemoryTraceRepository()), $runs);

        $now = $tool->execute([], new ToolContext($mine->id, 1, $owner, new NullCancellation()))->content;
        self::assertStringContainsString('Запуск #' . $mine->id . ' идёт сейчас: потрачено 4591 токенов за 2 вызовов модели', $now);

        $context = new ToolContext($theirs->id, 2, $user, new NullCancellation());
        self::assertStringContainsString('0 токенов', $tool->execute(['action' => 'run'], $context)->content);
        self::assertTrue($tool->execute(['run_id' => $mine->id], $context)->isError, 'a participant sees only their own runs');
        self::assertTrue($tool->execute(['action' => 'days'], $context)->isError, 'overall spend is the owner\'s');
    }

    /** @return array{SpendingTool, FakeTraceReports, \OpenCCK\Domain\Entity\Principal, \OpenCCK\Domain\Entity\Principal, int} */
    private static function spendTool(): array {
        $principals = new InMemoryPrincipalRepository();
        $owner = $principals->ensureOwner('telegram', '42', 'Сергей');
        $visitor = $principals->createVisitor('telegram', '7', 'Артур');
        $user = $principals->decideVisitor($visitor->id, Role::User, PrincipalStatus::Active) ?? $visitor;
        $conversations = new \OpenCCK\Test\Support\InMemoryConversationRepository();
        $topic = $conversations->openConversation($owner->id, 'telegram', '-100500', '119');
        $figures = new FakeTraceReports();
        $figures->breakdown = [
            ['chat' => 'Mercurius', 'chatId' => '-100500', 'thread' => '119', 'project' => 'OpenWRT', 'person' => null, 'label' => '', 'calls' => 10, 'input' => 300_000, 'output' => 7_000, 'cost' => 0.0221],
            ['chat' => null, 'chatId' => '42', 'thread' => '', 'project' => null, 'person' => 'Сергей', 'label' => '', 'calls' => 3, 'input' => 9_000, 'output' => 500, 'cost' => 0.5],
        ];
        $tool = new SpendingTool(new TraceReports($figures, new InMemoryTraceRepository()), new InMemoryRunRepository(), null, $conversations, $principals, 'Europe/Moscow');
        return [$tool, $figures, $owner, $user, $topic];
    }

    public function testSpendIsBrokenDownByTopicForAPeriodInTheOwnersZone(): void {
        [$tool, $figures, $owner, , $topic] = self::spendTool();

        $result = $tool->execute(['action' => 'breakdown', 'by' => 'topic', 'from' => '2026-11-01', 'to' => '2026-11-17'], new ToolContext(1, $topic, $owner, new NullCancellation()));

        self::assertFalse($result->isError, $result->content);
        $query = $figures->lastQuery;
        self::assertNotNull($query);
        self::assertSame('topic', $query->by);
        self::assertSame('2026-11-01T00:00:00+03:00', $query->from->format('c'), 'days are the owner\'s days');
        self::assertSame('2026-11-18T00:00:00+03:00', $query->to->format('c'), 'the last day is included');
        self::assertNull($query->principalId);
        self::assertStringContainsString('Mercurius / OpenWRT', $result->content);
        self::assertStringContainsString('личный чат: Сергей', $result->content);
        self::assertStringContainsString('$0.5221', $result->content, 'the total');
    }

    public function testHereNarrowsToThisTopicAndAPersonIsFoundByName(): void {
        [$tool, $figures, $owner, $user, $topic] = self::spendTool();
        $context = new ToolContext(1, $topic, $owner, new NullCancellation());

        $tool->execute(['action' => 'breakdown', 'by' => 'day', 'days' => 7, 'here' => true, 'participant' => 'артур'], $context);

        $query = $figures->lastQuery;
        self::assertNotNull($query);
        self::assertSame(['telegram', '-100500', '119'], $query->place);
        self::assertSame($user->id, $query->principalId);
        self::assertTrue($tool->execute(['action' => 'breakdown', 'by' => 'chat', 'participant' => 'Никто'], $context)->isError);
        self::assertTrue($tool->execute(['action' => 'breakdown', 'by' => 'chat', 'from' => '17.11.2026'], $context)->isError, 'a date is YYYY-MM-DD');
    }

    public function testAParticipantSeesOnlyTheirOwnSpend(): void {
        [$tool, $figures, $owner, $user, $topic] = self::spendTool();

        $result = $tool->execute(['action' => 'breakdown', 'by' => 'topic', 'participant' => 'Сергей'], new ToolContext(1, $topic, $user, new NullCancellation()));

        self::assertFalse($result->isError);
        $query = $figures->lastQuery;
        self::assertNotNull($query);
        self::assertSame($user->id, $query->principalId, 'whoever they name, they get their own figures');
        self::assertNotSame($owner->id, $query->principalId);
    }
}
