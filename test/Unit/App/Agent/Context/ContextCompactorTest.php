<?php

declare(strict_types=1);

namespace OpenCCK\Test\Unit\App\Agent\Context;

use Amp\PHPUnit\AsyncTestCase;
use DateTimeImmutable;
use Mercurius\Llm\ErrorKind;
use Mercurius\Llm\LlmException;
use Mercurius\Llm\Message;
use Mercurius\Llm\Role;
use Mercurius\Llm\ToolCall;
use OpenCCK\App\Agent\Context\ContextCompactor;
use OpenCCK\App\Agent\Context\SummaryPrompt;
use OpenCCK\Domain\Agent\DangerClass;
use OpenCCK\Domain\Agent\Run;
use OpenCCK\Domain\Agent\RunLimits;
use OpenCCK\Domain\Agent\StoredToolCall;
use OpenCCK\Domain\Agent\ToolCallStatus;
use OpenCCK\Domain\Agent\TranscriptEntry;
use OpenCCK\Test\Support\InMemoryConversationRepository;
use OpenCCK\Test\Support\InMemoryLlmCallRepository;
use OpenCCK\Test\Support\InMemoryRunRepository;
use OpenCCK\Test\Support\InMemoryTranscriptRepository;
use OpenCCK\Test\Support\ScriptedLlm;
use Psr\Log\NullLogger;

/**
 * Context compaction: a 4096-token window, so the threshold is 3072 tokens and the protected tail 1024.
 */
final class ContextCompactorTest extends AsyncTestCase {
    private const WINDOW = 4096;

    private InMemoryRunRepository $runs;
    private InMemoryTranscriptRepository $transcript;
    private InMemoryConversationRepository $conversations;
    private ScriptedLlm $llm;
    private Run $run;
    private DateTimeImmutable $now;
    private int $calls = 0;

    protected function setUp(): void {
        parent::setUp();
        $this->runs = new InMemoryRunRepository();
        $this->transcript = new InMemoryTranscriptRepository();
        $this->conversations = new InMemoryConversationRepository();
        $this->conversations->openConversation(1, 'test', '1');
        $this->llm = new ScriptedLlm();
        $this->now = new DateTimeImmutable('2026-11-16 12:00:00');
        $run = $this->runs->start(1, 1, 'main/model', new RunLimits(30, 100_000, 900, 120));
        self::assertNotNull($run);
        $this->run = $run;
    }

    private function compactor(): ContextCompactor {
        return new ContextCompactor(
            $this->llm,
            $this->transcript,
            $this->conversations,
            $this->runs,
            new InMemoryLlmCallRepository(),
            self::WINDOW,
            'cheap/model',
            new NullLogger(),
            fn(): DateTimeImmutable => $this->now,
        );
    }

    private function user(string $text): void {
        $this->transcript->appendUser(1, $this->run->id, $text);
    }

    private function say(string $text): void {
        $this->transcript->appendAssistant(1, $this->run->id, $text, []);
    }

    private function tool(string $result, string $name = 'read_file'): void {
        $id = 'c' . ++$this->calls;
        $this->transcript->appendAssistant(1, $this->run->id, '', [new StoredToolCall($id, 0, $name, [], '{}', DangerClass::Read, ToolCallStatus::Pending)]);
        $this->transcript->appendToolResult(1, $this->run->id, $id, ToolCallStatus::Done, $result);
    }

    /** @return list<string> */
    private function contents(): array {
        return array_map(static fn(Message $m): string => $m->content, $this->transcript->runMessages($this->run->id));
    }

    public function testBelowThresholdNothingChanges(): void {
        $this->user('задача');
        $this->tool(str_repeat('x', 500));

        self::assertFalse($this->compactor()->ensure($this->run, 'system', 40, 100));
        self::assertSame(str_repeat('x', 500), $this->contents()[2]);
    }

    public function testOldToolResultsArePrunedFirst(): void {
        $this->user('задача');
        $this->tool(str_repeat('x', 500));
        $this->tool(str_repeat('y', 150));
        $this->tool(str_repeat('z', 4400));

        self::assertTrue($this->compactor()->ensure($this->run, 'system', 40, 4000));

        $contents = $this->contents();
        self::assertSame(str_repeat('x', 200) . "\n[Старый результат инструмента убран из контекста (500 символов).]", $contents[2]);
        self::assertSame(str_repeat('y', 150), $contents[4]);
        self::assertSame(str_repeat('z', 4400), $contents[6]);
    }

    public function testPrunedSpilledResultKeepsItsPath(): void {
        $this->user('задача');
        $this->tool(str_repeat('x', 1500) . "\n…\n[Полный результат (90000 символов) сохранён: /workspace/p1/.results/a.txt. Читай его через read_file.]");
        $this->tool(str_repeat('z', 4400));

        $this->compactor()->ensure($this->run, 'system', 40, 4000);

        self::assertStringContainsString('Полный — в /workspace/p1/.results/a.txt', $this->contents()[2]);
    }

    public function testPruningEnoughMeansNoSummary(): void {
        $this->user('задача');
        $this->tool(str_repeat('x', 9000));
        $this->tool(str_repeat('z', 4400));

        $this->compactor()->ensure($this->run, 'system', 40, 4000);

        self::assertSame([], $this->llm->requests);
        self::assertNull($this->conversations->compaction(1)->summary);
        self::assertSame(0, $this->runs->find($this->run->id)?->promptAnchor);
    }

    public function testTailNeverStartsWithAToolResult(): void {
        $entries = [
            new TranscriptEntry(1, Message::user('задача')),
            new TranscriptEntry(2, Message::assistant('', [new ToolCall('a', 'read_file', '{}'), new ToolCall('b', 'read_file', '{}')])),
            new TranscriptEntry(3, Message::toolResult('a', 'короткий'), 'read_file'),
            new TranscriptEntry(4, Message::toolResult('b', str_repeat('z', 8000)), 'read_file'),
        ];

        self::assertSame(1, ContextCompactor::boundary($entries, 1024));
        self::assertSame(Role::Assistant, $entries[ContextCompactor::boundary($entries, 1024)]->message->role);
    }

    /** A middle the pruning cannot shrink: long thoughts of the agent, then a tail of 4400 characters. */
    private function longRun(): void {
        $this->user('исходная задача');
        $this->say(str_repeat('думаю ', 2000));
        $this->user('уточнение посреди задачи');
        $this->say(str_repeat('ещё ', 1000));
        $this->tool(str_repeat('z', 4400));
    }

    public function testMiddleIsSummarisedByTheCheapModel(): void {
        $this->longRun();
        $this->llm = new ScriptedLlm([ScriptedLlm::text('Цель: задача. Сделано: подумал.')]);

        self::assertTrue($this->compactor()->ensure($this->run, 'system', 40, 4000));

        $request = $this->llm->requests[0];
        self::assertSame('cheap/model', $request->model);
        self::assertSame([], $request->tools);
        self::assertStringContainsString('думаю', $request->messages[1]->content);
        $compaction = $this->conversations->compaction(1);
        self::assertSame('Цель: задача. Сделано: подумал.', $compaction->summary);
        self::assertStringStartsWith(SummaryPrompt::HEADER, (string) $this->compactor()->summaryMessage(1));
        self::assertSame(0, $this->runs->find($this->run->id)?->turns, 'compaction takes no turn of the run');
        // The tail starts at the call whose result is the last message.
        self::assertSame(4, $compaction->uptoId);
    }

    public function testUserMessagesOfTheCompactedPartStayVerbatim(): void {
        $this->longRun();
        $this->llm = new ScriptedLlm([ScriptedLlm::text('резюме')]);

        $this->compactor()->ensure($this->run, 'system', 40, 4000);

        $summary = (string) $this->compactor()->summaryMessage(1);
        self::assertStringContainsString("- исходная задача\n- уточнение посреди задачи", $summary);
    }

    public function testSecondCompactionUpdatesTheSummary(): void {
        $this->longRun();
        $this->llm = new ScriptedLlm([ScriptedLlm::text('первое резюме'), ScriptedLlm::text('второе резюме')]);
        $this->compactor()->ensure($this->run, 'system', 40, 4000);
        $this->say(str_repeat('снова ', 2000));
        $this->tool(str_repeat('w', 4400));

        $this->compactor()->ensure($this->run, 'system', 40, 4000);

        self::assertStringContainsString('первое резюме', $this->llm->requests[1]->messages[1]->content);
        self::assertStringContainsString('второе резюме', (string) $this->conversations->compaction(1)->summary);
        self::assertStringNotContainsString('думаю', $this->llm->requests[1]->messages[1]->content);
    }

    public function testFailuresBackOffThenFallBackToAStub(): void {
        $this->longRun();
        $down = new LlmException(ErrorKind::Server, 'down');
        $this->llm = new ScriptedLlm([$down, $down, $down]);
        $compactor = $this->compactor();

        $compactor->ensure($this->run, 'system', 40, 3200);
        self::assertSame(1, $this->conversations->compaction(1)->failures);
        $compactor->ensure($this->run, 'system', 40, 3200);
        self::assertCount(1, $this->llm->requests, 'waits out 60 s');

        $this->now = $this->now->modify('+61 seconds');
        $compactor->ensure($this->run, 'system', 40, 3200);
        $this->now = $this->now->modify('+299 seconds');
        $compactor->ensure($this->run, 'system', 40, 3200);
        self::assertCount(2, $this->llm->requests, 'waits out 300 s');
        $this->now = $this->now->modify('+2 seconds');
        $compactor->ensure($this->run, 'system', 40, 3200);
        $this->now = $this->now->modify('+901 seconds');
        $compactor->ensure($this->run, 'system', 40, 3200);

        self::assertCount(3, $this->llm->requests);
        $compaction = $this->conversations->compaction(1);
        self::assertSame(0, $compaction->failures);
        self::assertStringContainsString(SummaryPrompt::STUB, (string) $compaction->summary);
        self::assertStringContainsString('- исходная задача', (string) $compactor->summaryMessage(1));
    }

    public function testNearlyFullDuringCooldownUsesTheStub(): void {
        $this->longRun();
        $this->llm = new ScriptedLlm([new LlmException(ErrorKind::Server, 'down')]);
        $compactor = $this->compactor();
        $compactor->ensure($this->run, 'system', 40, 3200);

        self::assertTrue($compactor->ensure($this->run, 'system', 40, 4000));

        self::assertCount(1, $this->llm->requests);
        self::assertStringContainsString(SummaryPrompt::STUB, (string) $this->conversations->compaction(1)->summary);
    }

    public function testStubsDoNotRepeatTheUserList(): void {
        $this->longRun();
        $this->failThreeTimes();
        $compactor = $this->compactor();
        $compactor->ensure($this->run, 'system', 40, 4000);
        $this->say(str_repeat('снова ', 2000));
        $this->tool(str_repeat('w', 4400));
        $this->failThreeTimes();

        $compactor->ensure($this->run, 'system', 40, 4000);

        self::assertSame([], $this->llm->requests);
        self::assertSame(1, substr_count((string) $compactor->summaryMessage(1), '- исходная задача'));
        self::assertSame(0, substr_count((string) $this->conversations->compaction(1)->summary, '- исходная задача'));
    }

    private function failThreeTimes(): void {
        for ($i = 0; $i < 3; $i++) {
            $this->conversations->compactionFailed(1, $this->now->modify('-1 second'));
        }
    }

    public function testUserListIsCappedNewestFirst(): void {
        for ($i = 1; $i <= 20; $i++) {
            $this->user(sprintf('сообщение %02d ', $i) . str_repeat('а', 3000));
        }
        $this->conversations->saveSummary(1, 'резюме', 1000);

        $message = (string) $this->compactor()->summaryMessage(1);

        self::assertLessThan(self::WINDOW * 4, mb_strlen($message));
        self::assertStringContainsString('сообщение 20', $message);
        self::assertStringNotContainsString('сообщение 01', $message);
        self::assertStringContainsString('ранних сообщений не показано', $message);
    }

    public function testSecretsOfEarlierUserMessagesDoNotReachTheCheapModel(): void {
        $this->user('пароль от базы password=hunter2');
        $this->longRun();
        $this->llm = new ScriptedLlm([ScriptedLlm::text('первое'), ScriptedLlm::text('второе')]);
        $compactor = $this->compactor();
        $compactor->ensure($this->run, 'system', 40, 4000);
        $this->say(str_repeat('снова ', 2000));
        $this->tool(str_repeat('w', 4400));

        $compactor->ensure($this->run, 'system', 40, 4000);

        self::assertCount(2, $this->llm->requests);
        foreach ($this->llm->requests as $request) {
            self::assertStringNotContainsString('hunter2', $request->messages[1]->content);
        }
    }
}
