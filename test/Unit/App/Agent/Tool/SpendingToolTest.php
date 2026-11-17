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
}
