<?php

declare(strict_types=1);

namespace OpenCCK\Test\Unit\App\Agent;

use Amp\NullCancellation;
use Amp\PHPUnit\AsyncTestCase;
use Mercurius\Llm\ChatRequest;
use Mercurius\Llm\ChatResponse;
use Mercurius\Llm\ErrorKind;
use Mercurius\Llm\FinishReason;
use Mercurius\Llm\LlmException;
use Mercurius\Llm\Message;
use Mercurius\Llm\Role;
use Mercurius\Llm\ToolCall;
use Mercurius\Llm\Usage;
use OpenCCK\App\Agent\AgentLoop;
use OpenCCK\App\Agent\Context\ContextCompactor;
use OpenCCK\App\Agent\Context\SummaryPrompt;
use OpenCCK\App\Agent\Tool\ToolContext;
use OpenCCK\App\Agent\Tool\ToolExecutor;
use OpenCCK\App\Agent\Tool\ToolRegistry;
use OpenCCK\App\Agent\Tool\ToolResult;
use OpenCCK\Domain\Agent\ParticipantPolicy;
use OpenCCK\Domain\Agent\Run;
use OpenCCK\Domain\Agent\RunLimits;
use OpenCCK\Domain\Agent\RunStatus;
use OpenCCK\Domain\Agent\StopReason;
use OpenCCK\Domain\Entity\Principal;
use OpenCCK\Domain\Entity\PrincipalKind;
use OpenCCK\Domain\Entity\Role as ParticipantRole;
use OpenCCK\Test\Support\FakeTool;
use OpenCCK\Test\Support\InMemoryConversationRepository;
use OpenCCK\Test\Support\InMemoryLlmCallRepository;
use OpenCCK\Test\Support\InMemoryRunRepository;
use OpenCCK\Test\Support\InMemoryTranscriptRepository;
use OpenCCK\Test\Support\RecordingObserver;
use OpenCCK\Test\Support\ScriptedLlm;
use Psr\Log\NullLogger;

/**
 * Compaction inside the loop: a 4096-token window; scripted answers report no prompt tokens, so the loop estimates
 * by characters, as it does right after a compaction.
 */
final class ContextCompactionLoopTest extends AsyncTestCase {
    private InMemoryRunRepository $runs;
    private InMemoryTranscriptRepository $transcript;
    private InMemoryConversationRepository $conversations;
    private ToolRegistry $registry;

    protected function setUp(): void {
        parent::setUp();
        $this->runs = new InMemoryRunRepository();
        $this->transcript = new InMemoryTranscriptRepository();
        $this->conversations = new InMemoryConversationRepository();
        $this->conversations->openConversation(1, 'test', '1');
        $this->registry = new ToolRegistry();
        $this->registry->register(new FakeTool('read', handler: static fn(array $args, ToolContext $c): ToolResult => ToolResult::ok(str_repeat('r', 5000) . ' #' . bin2hex(random_bytes(4)))));
    }

    private static function call(string $id, string $thought = ''): ChatResponse {
        return new ChatResponse($thought, [new ToolCall($id, 'read', '{}')], FinishReason::ToolCalls, new Usage(0, 10), 'test/model', 'gen');
    }

    private static function answer(string $text): ChatResponse {
        return new ChatResponse($text, [], FinishReason::Stop, new Usage(0, 10), 'test/model', 'gen');
    }

    private function loop(ScriptedLlm $llm): AgentLoop {
        $compactor = new ContextCompactor($llm, $this->transcript, $this->conversations, $this->runs, new InMemoryLlmCallRepository(), 4096, 'cheap/model', new NullLogger());
        return new AgentLoop($llm, $this->runs, $this->transcript, $this->conversations, new InMemoryLlmCallRepository(), $this->registry, new ToolExecutor(), 100, null, 40, compactor: $compactor);
    }

    private function execute(AgentLoop $loop, string $task): Run {
        $run = $this->runs->start(1, 1, 'test/model', new RunLimits(30, 1_000_000, 900, 120));
        self::assertNotNull($run);
        $this->transcript->appendUser(1, $run->id, $task);
        $owner = new Principal(1, PrincipalKind::Human, ParticipantRole::Owner, null);
        return $loop->run($run, new ParticipantPolicy(null, $run->limits, null, false), $owner, new RecordingObserver(), new NullCancellation());
    }

    /** Every tool result in the request follows the call that produced it. */
    private static function assertPairsIntact(ChatRequest $request): void {
        $open = [];
        foreach ($request->messages as $message) {
            foreach ($message->toolCalls as $call) {
                $open[$call->id] = true;
            }
            if ($message->role === Role::Tool) {
                self::assertArrayHasKey((string) $message->toolCallId, $open, 'tool result without its call');
            }
        }
    }

    /** @return list<ChatRequest> requests to the main model */
    private static function main(ScriptedLlm $llm): array {
        return array_values(array_filter($llm->requests, static fn(ChatRequest $r): bool => $r->model === 'test/model'));
    }

    private static function lastMain(ScriptedLlm $llm): ChatRequest {
        $main = self::main($llm);
        self::assertNotSame([], $main);
        $last = end($main);
        self::assertInstanceOf(ChatRequest::class, $last);
        return $last;
    }

    private static function text(ChatRequest $request): string {
        return implode("\n", array_map(static fn(Message $m): string => $m->content, $request->messages));
    }

    public function testLongRunIsCompactedAndContinues(): void {
        $llm = new ScriptedLlm([
            self::call('c1', str_repeat('думаю ', 1000)),
            self::call('c2'),
            self::call('c3'),
            ScriptedLlm::text('резюме середины'),
            self::answer('готово'),
        ]);

        $final = $this->execute($this->loop($llm), 'исходная задача');

        self::assertSame(RunStatus::Done, $final->status);
        $last = self::lastMain($llm);
        self::assertPairsIntact($last);
        $texts = self::text($last);
        self::assertStringContainsString(\Mercurius\I18n\t(SummaryPrompt::HEADER), $texts);
        self::assertStringContainsString('исходная задача', $texts);
        self::assertStringNotContainsString('думаю думаю', $texts);
        self::assertSame(Role::User, $last->messages[1]->role);
    }

    public function testResumedRunSeesTheSummary(): void {
        $llm = new ScriptedLlm([
            self::call('c1', str_repeat('думаю ', 1000)),
            self::call('c2'),
            self::call('c3'),
            ScriptedLlm::text('резюме середины'),
            self::answer('готово'),
            self::answer('второй ответ'),
        ]);
        $loop = $this->loop($llm);
        $this->execute($loop, 'исходная задача');

        $this->execute($loop, 'новый вопрос');

        $request = self::lastMain($llm);
        self::assertStringStartsWith(\Mercurius\I18n\t(SummaryPrompt::HEADER), $request->messages[1]->content);
        $messages = $request->messages;
        $lastMessage = end($messages);
        self::assertInstanceOf(Message::class, $lastMessage);
        self::assertSame('новый вопрос', $lastMessage->content);
        self::assertStringNotContainsString('думаю думаю', self::text($request));
    }

    public function testContextLengthErrorCompactsOnceAndRetries(): void {
        $llm = new ScriptedLlm([new LlmException(ErrorKind::ContextLength, 'too long'), self::answer('ок')]);

        $final = $this->execute($this->loop($llm), 'вопрос');

        self::assertSame(RunStatus::Done, $final->status);
        self::assertCount(2, self::main($llm));
    }

    public function testSecondContextLengthErrorFailsTheRun(): void {
        $tooLong = new LlmException(ErrorKind::ContextLength, 'too long');
        $llm = new ScriptedLlm([$tooLong, $tooLong]);

        $final = $this->execute($this->loop($llm), 'вопрос');

        self::assertSame(StopReason::ModelFailed, $final->stopReason);
    }
}
