<?php

declare(strict_types=1);

namespace OpenCCK\Test\Unit\App\Agent;

use Amp\NullCancellation;
use Amp\PHPUnit\AsyncTestCase;
use Mercurius\Llm\OpenRouter\OpenRouterClient;
use Mercurius\Llm\RetryingClient;
use OpenCCK\App\Agent\AgentLoop;
use OpenCCK\App\Agent\Tool\ToolExecutor;
use OpenCCK\App\Agent\Tool\ToolRegistry;
use OpenCCK\Domain\Agent\ParticipantPolicy;
use OpenCCK\Domain\Agent\Run;
use OpenCCK\Domain\Agent\RunLimits;
use OpenCCK\Domain\Agent\StopReason;
use OpenCCK\Domain\Entity\Member;
use OpenCCK\Domain\Entity\MemberKind;
use OpenCCK\Domain\Entity\Role;
use OpenCCK\Test\Support\FakeTool;
use OpenCCK\Test\Support\InMemoryConversationRepository;
use OpenCCK\Test\Support\InMemoryLlmCallRepository;
use OpenCCK\Test\Support\InMemoryRunRepository;
use OpenCCK\Test\Support\InMemoryTranscriptRepository;
use OpenCCK\Test\Support\QueuedHttp;
use OpenCCK\Test\Support\RecordingObserver;

use function Amp\delay;

/**
 * The loop over the real OpenRouter client and a provider that misbehaves on the wire: a stream that stalls before its
 * first event, a stream that breaks in the middle of a tool call. ScriptedLlm hides both.
 *
 * spec: brief/agent-loop.md#запуск-и-цикл
 */
final class AgentLoopOverHttpTest extends AsyncTestCase {
    private InMemoryRunRepository $runs;
    private InMemoryTranscriptRepository $transcript;
    private InMemoryConversationRepository $conversations;
    private ToolRegistry $registry;
    private RecordingObserver $observer;
    private int $conversationId;

    protected function setUp(): void {
        parent::setUp();
        $this->runs = new InMemoryRunRepository();
        $this->transcript = new InMemoryTranscriptRepository();
        $this->conversations = new InMemoryConversationRepository();
        $this->registry = new ToolRegistry();
        $this->observer = new RecordingObserver();
        $this->conversationId = $this->conversations->openConversation(1, 'test', '1');
        $this->conversations->setMeta($this->conversationId, 'системный промпт', 'a/m');
    }

    private function runOver(QueuedHttp $http): Run {
        $client = new OpenRouterClient($http->client(), 'sk', firstEventTimeout: 0.3, eventTimeout: 0.3);
        $llm = new RetryingClient($client, maxAttempts: 2, sleep: static function (float $seconds): void {});
        $loop = new AgentLoop($llm, $this->runs, $this->transcript, $this->conversations, new InMemoryLlmCallRepository(), $this->registry, new ToolExecutor(), 100, null, 10);
        $run = $this->runs->start($this->conversationId, 1, 'a/m', new RunLimits(30, 100_000, 900, 120));
        self::assertNotNull($run);
        $this->transcript->appendUser($this->conversationId, $run->id, 'задача');
        $owner = new Member(1, MemberKind::Human, Role::owner(), null);
        return $loop->run($run, new ParticipantPolicy(null, $run->limits, null, true), $owner, $this->observer, new NullCancellation());
    }

    public function testAStreamStalledBeforeItsFirstEventIsAskedAgain(): void {
        $http = (new QueuedHttp())
            ->stream((static function (): \Generator {
                for ($i = 0; $i < 40; $i++) {
                    yield ": OPENROUTER PROCESSING\n\n";
                    delay(0.05);
                }
            })())
            ->stream(QueuedHttp::text('Ответ.'));

        $run = $this->runOver($http);

        self::assertSame(StopReason::Answered, $run->stopReason);
        self::assertSame('Ответ.', $this->observer->final);
        self::assertCount(2, $http->bodies);
    }

    public function testAStreamBrokenInTheMiddleOfAToolCallRunsTheCallOnceFromTheRepeat(): void {
        $tool = new FakeTool('weather');
        $this->registry->register($tool);
        $half = ['index' => 0, 'id' => 'c1', 'type' => 'function', 'function' => ['name' => 'weather', 'arguments' => '{"city":']];
        $http = (new QueuedHttp())
            ->stream((static function () use ($half): \Generator {
                yield QueuedHttp::event(['id' => 'g', 'model' => 'a/m', 'choices' => [['index' => 0, 'delta' => ['tool_calls' => [$half]]]]]);
                throw new \Amp\ByteStream\StreamException('connection reset');
            })())
            ->stream(QueuedHttp::toolCall('c1', 'weather', '{"city":"Paris"}'))
            ->stream(QueuedHttp::text('Солнечно.'));

        $run = $this->runOver($http);

        self::assertSame(StopReason::Answered, $run->stopReason);
        self::assertSame('Солнечно.', $this->observer->final);
        self::assertSame([['city' => 'Paris']], $tool->calls, 'the half of the call is not run');
    }
}
