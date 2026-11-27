<?php

declare(strict_types=1);

namespace OpenCCK\Test\Unit\App\Agent;

use Amp\DeferredCancellation;
use Amp\NullCancellation;
use Amp\PHPUnit\AsyncTestCase;
use Mercurius\Llm\Message;
use Mercurius\Llm\Role as LlmRole;
use Mercurius\Llm\ToolCall;
use OpenCCK\App\Agent\AgentLoop;
use OpenCCK\App\Agent\ShutdownException;
use OpenCCK\App\Agent\Tool\ToolExecutor;
use OpenCCK\App\Agent\Tool\ToolRegistry;
use OpenCCK\App\Agent\Tool\ToolResult;
use OpenCCK\Domain\Agent\DangerClass;
use OpenCCK\Domain\Agent\ParticipantPolicy;
use OpenCCK\Domain\Agent\Run;
use OpenCCK\Domain\Agent\RunLimits;
use OpenCCK\Domain\Agent\RunStatus;
use OpenCCK\Domain\Agent\StopReason;
use OpenCCK\Domain\Entity\Member;
use OpenCCK\Domain\Entity\MemberKind;
use OpenCCK\Domain\Entity\Role;
use OpenCCK\Test\Support\FakeTool;
use OpenCCK\Test\Support\InMemoryConversationRepository;
use OpenCCK\Test\Support\InMemoryLlmCallRepository;
use OpenCCK\Test\Support\InMemoryRunRepository;
use OpenCCK\Test\Support\InMemoryTranscriptRepository;
use OpenCCK\Test\Support\RecordingObserver;
use OpenCCK\Test\Support\ScriptedLlm;
use RuntimeException;

use function Amp\async;
use function Amp\delay;

final class AgentLoopTest extends AsyncTestCase {
    private InMemoryRunRepository $runs;
    private InMemoryTranscriptRepository $transcript;
    private InMemoryConversationRepository $conversations;
    private InMemoryLlmCallRepository $calls;
    private ToolRegistry $registry;
    private RecordingObserver $observer;
    private Member $owner;
    private int $conversationId;

    protected function setUp(): void {
        parent::setUp();
        $this->runs = new InMemoryRunRepository();
        $this->transcript = new InMemoryTranscriptRepository();
        $this->conversations = new InMemoryConversationRepository();
        $this->calls = new InMemoryLlmCallRepository();
        $this->registry = new ToolRegistry();
        $this->observer = new RecordingObserver();
        $this->owner = new Member(1, MemberKind::Human, Role::admin(), null);
        $this->conversationId = $this->conversations->openConversation(1, 'test', '1');
        $this->conversations->setMeta($this->conversationId, 'системный промпт', 'test/model');
    }

    /** @param positive-int $maxTokens */
    private function loop(ScriptedLlm $llm, int $maxTokens = 100): AgentLoop {
        return new AgentLoop($llm, $this->runs, $this->transcript, $this->conversations, $this->calls, $this->registry, new ToolExecutor(), $maxTokens, null, 10);
    }

    private function start(string $task = 'задача', ?RunLimits $limits = null): Run {
        $run = $this->runs->start($this->conversationId, 1, 'test/model', $limits ?? new RunLimits(30, 100_000, 900, 120));
        self::assertNotNull($run);
        $this->transcript->appendUser($this->conversationId, $run->id, $task);
        return $run;
    }

    /** @param positive-int $maxTokens */
    private function execute(ScriptedLlm $llm, Run $run, int $maxTokens = 100, ?\Amp\Cancellation $cancellation = null, bool $readOnly = false): Run {
        $policy = new ParticipantPolicy(null, $run->limits, null, true, $readOnly);
        return $this->loop($llm, $maxTokens)->run($run, $policy, $this->owner, $this->observer, $cancellation ?? new NullCancellation());
    }

    /** @return list<Message> */
    private static function lastMessages(ScriptedLlm $llm): array {
        $request = end($llm->requests);
        self::assertNotFalse($request);
        return $request->messages;
    }

    public function testTextWrittenWithToolCallsReachesTheChat(): void {
        $this->registry->register(new FakeTool('spending'));
        $withText = ScriptedLlm::calls(new ToolCall('c1', 'spending', '{}'));
        $llm = new ScriptedLlm([
            new \Mercurius\Llm\ChatResponse('Вы отвечаете на #288. Теперь токены:', $withText->toolCalls, $withText->finishReason, $withText->usage, 'test/model', 'gen'),
            ScriptedLlm::calls(new ToolCall('c2', 'spending', '{"action":"recent"}')),
            ScriptedLlm::text('Токены: 9012.'),
        ]);

        $this->execute($llm, $this->start());

        self::assertSame(['Вы отвечаете на #288. Теперь токены:'], $this->observer->commentary, 'a step without text adds nothing');
        self::assertSame('Токены: 9012.', $this->observer->final);
    }

    public function testARequestToOpenAiNamesTheConversationForItsPromptCache(): void {
        $openAi = $this->runs->start($this->conversationId, 1, 'openai:gpt-5.5', new RunLimits(30, 100_000, 900, 120));
        self::assertNotNull($openAi);
        $this->transcript->appendUser($this->conversationId, $openAi->id, 'задача');
        $llm = new ScriptedLlm([ScriptedLlm::text('ok')]);
        $this->execute($llm, $openAi);
        self::assertSame('conversation-' . $this->conversationId, $llm->requests[0]->extra['prompt_cache_key'] ?? null);

        $other = new ScriptedLlm([ScriptedLlm::text('ok')]);
        $this->execute($other, $this->start());
        self::assertArrayNotHasKey('prompt_cache_key', $other->requests[0]->extra, 'only OpenAI takes the key');

        $codex = $this->runs->start($this->conversationId, 1, 'codex:gpt-6.1-codex', new RunLimits(30, 100_000, 900, 120));
        self::assertNotNull($codex);
        $responses = new ScriptedLlm([ScriptedLlm::text('ok')]);
        $this->execute($responses, $codex);
        self::assertSame('conversation-' . $this->conversationId, $responses->requests[0]->extra['prompt_cache_key'] ?? null, 'the Responses API takes it too');
    }

    public function testTwoStepTaskUsesToolsThenAnswers(): void {
        $this->registry->register(new FakeTool('search'));
        $this->registry->register(new FakeTool('save', DangerClass::Write));
        $llm = new ScriptedLlm([
            ScriptedLlm::calls(new ToolCall('c1', 'search', '{"q":"a"}')),
            ScriptedLlm::calls(new ToolCall('c2', 'save', '{"q":"b"}')),
            ScriptedLlm::text('Готово: сохранил.'),
        ]);

        $run = $this->execute($llm, $this->start());

        self::assertSame(RunStatus::Done, $run->status);
        self::assertSame(StopReason::Answered, $run->stopReason);
        self::assertSame(['search', 'save'], $this->observer->tools);
        self::assertSame('Готово: сохранил.', $this->observer->final);
        self::assertSame(3, $run->turns);
        self::assertSame('системный промпт', $llm->requests[0]->messages[0]->content);
        self::assertSame(['search', 'save'], array_map(static fn($d) => $d->name, $llm->requests[0]->tools));
        self::assertCount(3, $this->calls->calls);
        self::assertSame('agent', $this->calls->calls[0]->purpose);
    }

    public function testAToolCompletesItsArgumentsBeforeTheCallIsSaved(): void {
        $tool = new class implements \OpenCCK\App\Agent\Tool\Tool, \OpenCCK\App\Agent\Tool\PreparesCall {
            /** @var list<array<string, mixed>> */
            public array $ran = [];

            public function definition(): \Mercurius\Llm\ToolDefinition {
                return new \Mercurius\Llm\ToolDefinition('script', 'runs a file', ['type' => 'object', 'properties' => ['path' => ['type' => 'string']]]);
            }

            public function danger(array $args): DangerClass {
                return ($args['source'] ?? '') === 'act()' ? DangerClass::Write : DangerClass::Read;
            }

            public function untrusted(): bool {
                return false;
            }

            public function timeout(): int {
                return 10;
            }

            public function prepare(array $args, Member $principal, ParticipantPolicy $policy, \Amp\Cancellation $cancellation): array|string {
                return ($args['path'] ?? '') === 'missing.js' ? 'no such file' : $args + ['source' => 'act()'];
            }

            public function execute(array $args, \OpenCCK\App\Agent\Tool\ToolContext $context): ToolResult {
                $this->ran[] = $args;
                return ToolResult::ok('done');
            }
        };
        $this->registry->register($tool);
        $llm = new ScriptedLlm([
            ScriptedLlm::calls(new ToolCall('c1', 'script', '{"path":"a.js"}'), new ToolCall('c2', 'script', '{"path":"missing.js"}')),
            ScriptedLlm::text('ok'),
        ]);

        $run = $this->execute($llm, $this->start());

        self::assertSame([['path' => 'a.js', 'source' => 'act()']], $tool->ran, 'the completed arguments run');
        $stored = $this->transcript->calls[$run->id]['c1'] ?? null;
        self::assertNotNull($stored);
        self::assertSame(['path' => 'a.js', 'source' => 'act()'], $stored->args, 'and are saved');
        self::assertSame(DangerClass::Write, $stored->danger, 'the danger is of the completed call');
        self::assertSame('{"path":"a.js"}', $stored->argumentsJson, 'the model keeps its own arguments');
        $results = array_values(array_filter(self::lastMessages($llm), static fn(Message $m): bool => $m->role === LlmRole::Tool));
        self::assertStringContainsString('no such file', $results[1]->content ?? '');
    }

    public function testAnActionWrittenAsTextIsNotTakenForDone(): void {
        $save = new FakeTool('settings', DangerClass::Write);
        $this->registry->register($save);
        $llm = new ScriptedLlm([
            ScriptedLlm::text('[Сделано в этом запуске: settings {"action":"set"} → Настройка предложена владельцу]'),
            ScriptedLlm::calls(new ToolCall('c1', 'settings', '{"q":"set"}')),
            ScriptedLlm::text('Готово: настройка сохранена.'),
        ]);

        $run = $this->execute($llm, $this->start('давай'));

        self::assertCount(1, $save->calls, 'the agent was told nothing was done and called the tool');
        self::assertSame('Готово: настройка сохранена.', $this->observer->final);
        $asked = self::lastMessages($llm);
        self::assertSame(RunStatus::Done, $run->status);
        $nudged = array_filter($llm->requests[1]->messages, static fn(Message $m): bool => str_contains($m->content, 'инструмент не вызывал'));
        self::assertCount(1, $nudged, 'the second call carries the reminder');
        self::assertNotEmpty($asked);
    }

    public function testAModelOfAProseFamilyIsToldToActThroughTools(): void {
        // spec: brief/agent-loop.md#инструменты — the rule goes with a request that has tools, by the model of that call
        $this->registry->register(new FakeTool('spending'));
        $llm = new ScriptedLlm([ScriptedLlm::text('ok')]);
        $run = $this->runs->start($this->conversationId, 1, 'openrouter:z-ai/glm-5.3-flash', new RunLimits(30, 100_000, 900, 120));
        self::assertNotNull($run);
        $this->transcript->appendUser($this->conversationId, $run->id, 'задача');

        $this->execute($llm, $run);

        self::assertStringContainsString(\Mercurius\I18n\t('agent.tool_use_guidance'), $llm->requests[0]->messages[0]->content);
        $other = new ScriptedLlm([ScriptedLlm::text('ok')]);
        $this->execute($other, $this->start());
        self::assertStringNotContainsString(\Mercurius\I18n\t('agent.tool_use_guidance'), $other->requests[0]->messages[0]->content, 'test/model is not of such a family');
    }

    public function testACallThePolicyRefusesIsMarkedForTheReview(): void {
        // spec: brief/self-improvement.md#самоулучшение — the review counts calls the policy did not allow
        $this->registry->register(new FakeTool('search'));
        $this->registry->register(new FakeTool('shell'));
        $llm = new ScriptedLlm([
            ScriptedLlm::calls(new ToolCall('c1', 'shell', '{"q":"rm"}'), new ToolCall('c2', 'search', '{"q":"a"}')),
            ScriptedLlm::text('ok'),
        ]);
        $run = $this->start();

        $this->loop($llm)->run($run, new ParticipantPolicy(['search'], $run->limits, null, true), $this->owner, $this->observer, new NullCancellation());

        self::assertSame(['c1'], $this->transcript->refused);
    }

    public function testManyMcpToolsAreReachedThroughTheBridge(): void {
        // spec: brief/skills-mcp.md#mcp-ресурсы-и-поиск-инструментов — the search of MCP tools: the model gets names, calls a tool through tool_call
        $issue = new FakeTool('mcp_github_create_issue', DangerClass::Read, ['type' => 'object', 'properties' => ['title' => ['type' => 'string']]]);
        $this->registry->register($issue);
        for ($i = 1; $i <= 30; $i++) {
            $this->registry->register(new FakeTool('mcp_big_' . $i, DangerClass::Read, ['type' => 'object', 'properties' => ['x' => ['type' => 'string', 'description' => str_repeat('words ', 40)]]]));
        }
        $this->registry->register(new FakeTool('read_file'));
        $this->registry->register(new \OpenCCK\App\Agent\Tool\ToolSearchTool(\OpenCCK\App\Agent\Tool\ToolSearchTool::SEARCH, $this->registry));
        $llm = new ScriptedLlm([
            ScriptedLlm::calls(new ToolCall('c1', 'tool_call', '{"name":"mcp_github_create_issue","arguments":{"title":"Bug"}}')),
            ScriptedLlm::text('Готово.'),
        ]);
        $loop = $this->loop($llm);
        $loop->useToolSearch(new \OpenCCK\App\Agent\Tool\ToolSearch(static fn(string $model): int => 20_000));
        $run = $this->start();

        $loop->run($run, new ParticipantPolicy(null, $run->limits, null, true), $this->owner, $this->observer, new NullCancellation());

        self::assertSame(['read_file', 'tool_search', 'tool_describe', 'tool_call'], array_map(static fn($d) => $d->name, $llm->requests[0]->tools));
        self::assertSame([['title' => 'Bug']], $issue->calls, 'the tool itself ran');
        self::assertSame(['mcp_github_create_issue'], $this->observer->tools);
        $saved = [];
        foreach ($llm->requests[1]->messages as $message) {
            foreach ($message->toolCalls as $call) {
                $saved[] = $call->name;
            }
        }
        self::assertSame(['tool_call'], $saved, 'the history keeps the call as the model made it');
    }

    public function testADeniedAbilityIsCheckedInSelfInfoFirst(): void {
        // spec: brief/agent-loop.md#знания-агента-о-себе — "no, I cannot" about itself is said only after self_info
        $self = new FakeTool('self_info');
        $this->registry->register($self);
        $llm = new ScriptedLlm([
            ScriptedLlm::text('Нет, я не вижу такой возможности у себя.'),
            ScriptedLlm::calls(new ToolCall('c1', 'self_info', '{"q":"commands"}')),
            ScriptedLlm::text('Да: /owner <номер участника>.'),
        ]);

        $this->execute($llm, $this->start('могу ли я передать права владения другому?'));

        self::assertCount(1, $self->calls, 'the agent looked itself up');
        self::assertSame('Да: /owner <номер участника>.', $this->observer->final);
        $nudged = array_filter($llm->requests[1]->messages, static fn(Message $m): bool => str_contains($m->content, 'self_info'));
        self::assertNotEmpty($nudged, 'the second call carries the reminder');
    }

    public function testADenialIsNotSentToSelfInfoWhenTheParticipantHasNoSuchTool(): void {
        // spec: brief/agent-loop.md#знания-агента-о-себе — the reminder points only to a tool the one who asks may call
        $self = new FakeTool('self_info');
        $this->registry->register($self);
        $this->registry->register(new FakeTool('search'));
        $llm = new ScriptedLlm([ScriptedLlm::text('Не могу посмотреть это фото.')]);
        $run = $this->start('что на фото?');

        $this->loop($llm)->run($run, new ParticipantPolicy(['search'], $run->limits, null, true), $this->owner, $this->observer, new NullCancellation());

        self::assertSame('Не могу посмотреть это фото.', $this->observer->final, 'the answer goes out as it is');
        self::assertCount(1, $llm->requests, 'and the model is not asked to call a tool it does not have');
    }

    public function testTheWordsOfARealDenialAreCaught(): void {
        // answers that went out without a look in self_info
        $self = new FakeTool('self_info');
        $this->registry->register($self);
        $looked = 0;
        foreach ([
            'Всё так же — нет. У меня нет инструмента передачи прав владения другому участнику.',
            'Так что через меня передать владение нельзя.',
            'Передать права владения другому человеку — не могу.',
            "Sorry, I can't transfer ownership.",
        ] as $answer) {
            $llm = new ScriptedLlm([ScriptedLlm::text($answer), ScriptedLlm::calls(new ToolCall('c1', 'self_info', '{}')), ScriptedLlm::text('Да: /owner.')]);

            $this->execute($llm, $this->start('могу ли я передать владение?'));

            self::assertCount(++$looked, $self->calls, $answer);
        }
    }

    public function testANameTheAgentDoesNotKnowIsLookedUpInSelfInfo(): void {
        // spec: brief/agent-loop.md#знания-агента-о-себе — a module, a provider or a command of its own may stand behind
        //       a name the model does not know: answers that went out while the module was running
        $self = new FakeTool('self_info');
        $this->registry->register($self);
        $looked = 0;
        foreach ([
            'Не знаю сервиса «антигравити» — не встречал такого названия. Дайте ссылку на сайт.',
            'Я не знаю, что такое «антигравити» в данном контексте — нет такого сервиса или документации у меня под рукой.',
            "I'm not familiar with a service called Antigravity.",
        ] as $answer) {
            $llm = new ScriptedLlm([ScriptedLlm::text($answer), ScriptedLlm::calls(new ToolCall('c1', 'self_info', '{}')), ScriptedLlm::text('Вход — /antigravity login.')]);

            $this->execute($llm, $this->start('авторизуй подписку антигравити'));

            self::assertCount(++$looked, $self->calls, $answer);
        }
    }

    public function testAnAnswerAboutTheWorldIsNotADenialOfAnAbility(): void {
        // spec: brief/agent-loop.md#знания-агента-о-себе — «так делать нельзя» says nothing of the agent: the answer goes out as it is
        $self = new FakeTool('self_info');
        $this->registry->register($self);
        foreach ([
            'Спирт туда лить нельзя: пластик помутнеет. Протрите влажной салфеткой.',
            'Вернуть билет после вылета невозможно, но сбор за багаж вернут.',
            'У него нет возможности приехать раньше пятницы.',
        ] as $answer) {
            $llm = new ScriptedLlm([ScriptedLlm::text($answer)]);

            $this->execute($llm, $this->start('что будет, если так сделать?'));

            self::assertCount(1, $llm->requests, $answer);
            self::assertSame($answer, $this->observer->final);
        }
        self::assertCount(0, $self->calls);
    }

    public function testADenialAfterSelfInfoIsLetThrough(): void {
        $this->registry->register(new FakeTool('self_info'));
        $llm = new ScriptedLlm([
            ScriptedLlm::calls(new ToolCall('c1', 'self_info', '{"q":"tools"}')),
            ScriptedLlm::text('Нет, такой возможности у меня нет.'),
        ]);

        $this->execute($llm, $this->start('умеешь звонить?'));

        self::assertCount(2, $llm->requests, 'checked already: no reminder');
    }

    public function testAReportOfWhatThisRunReallyDidIsNotDoubted(): void {
        $save = new FakeTool('settings', DangerClass::Write);
        $this->registry->register($save);
        $llm = new ScriptedLlm([
            ScriptedLlm::calls(new ToolCall('c1', 'settings', '{"q":"set"}')),
            ScriptedLlm::text('Сохранил. [Сделано в этом запуске: settings {"q":"set"} → settings:{"q":"set"}]'),
        ]);

        $run = $this->execute($llm, $this->start('давай'));

        self::assertSame(RunStatus::Done, $run->status);
        self::assertCount(2, $llm->requests, 'the run did act: its own report is not taken for a fake one');
        self::assertCount(1, $save->calls);
    }

    /** spec: brief/agent-loop.md#контекст — an action of a connection of the run's context is an action too */
    public function testAnActionOfAContextConnectionIsNotDoubted(): void {
        $create = new FakeTool('mcp_github_create_issue', DangerClass::Write);
        $this->registry->registerIn('member:1', $create);
        $llm = new ScriptedLlm([
            ScriptedLlm::calls(new ToolCall('c1', 'mcp_github_create_issue', '{"q":"bug"}')),
            ScriptedLlm::text('Создал. [Сделано в этом запуске: mcp_github_create_issue {"q":"bug"} → ok]'),
        ]);
        $run = $this->start('заведи задачу');
        $policy = (new ParticipantPolicy(null, $run->limits, null, true))->withMcpContext('member:1', open: true);

        $done = $this->loop($llm, 100)->run($run, $policy, $this->owner, $this->observer, new NullCancellation());

        self::assertSame(RunStatus::Done, $done->status);
        self::assertCount(2, $llm->requests, 'its own report is not taken for a fake one: no second call');
        self::assertCount(1, $create->calls);
    }

    public function testATextReportTwiceIsLetThrough(): void {
        $llm = new ScriptedLlm([
            ScriptedLlm::text('[Сделано в этом запуске: x → y]'),
            ScriptedLlm::text('[Сделано в этом запуске: x → y]'),
        ]);

        $run = $this->execute($llm, $this->start());

        self::assertSame(RunStatus::Done, $run->status, 'one reminder, then the answer stands: no loop');
        self::assertCount(2, $llm->requests);
    }

    public function testToolErrorGoesBackToModel(): void {
        $this->registry->register(new FakeTool('t', handler: static fn(): ToolResult => ToolResult::error('нет доступа')));
        $llm = new ScriptedLlm([ScriptedLlm::calls(new ToolCall('c1', 't', '{}')), ScriptedLlm::text('не вышло')]);

        $run = $this->execute($llm, $this->start());

        self::assertSame(RunStatus::Done, $run->status);
        $tool = self::lastMessages($llm)[3];
        self::assertSame(LlmRole::Tool, $tool->role);
        self::assertSame('нет доступа', $tool->content);
    }

    public function testUnknownToolGetsErrorResultAndRunContinues(): void {
        $this->registry->register(new FakeTool('t'));
        $llm = new ScriptedLlm([ScriptedLlm::calls(new ToolCall('c1', 'nope', '{}')), ScriptedLlm::text('ок')]);

        $run = $this->execute($llm, $this->start());

        self::assertSame(RunStatus::Done, $run->status);
        self::assertStringStartsWith('Неизвестный инструмент «nope»', self::lastMessages($llm)[3]->content);
    }

    public function testNothingExecutesWhenPersistFails(): void {
        $tool = new FakeTool('t');
        $this->registry->register($tool);
        $this->transcript->beforeAssistant = static function (): void {
            throw new RuntimeException('db down');
        };
        $llm = new ScriptedLlm([ScriptedLlm::calls(new ToolCall('c1', 't', '{}'))]);

        $run = $this->execute($llm, $this->start());

        self::assertSame(StopReason::PersistFailed, $run->stopReason);
        self::assertSame([], $tool->calls);
    }

    public function testTruncatedArgumentsRetryOnceThenStop(): void {
        $tool = new FakeTool('t');
        $this->registry->register($tool);
        $cut = ScriptedLlm::truncated(new ToolCall('c1', 't', '{"q": "ab'));
        $llm = new ScriptedLlm([$cut, $cut]);

        $run = $this->execute($llm, $this->start());

        self::assertSame(StopReason::Truncated, $run->stopReason);
        self::assertCount(2, $llm->requests);
        self::assertSame([], $tool->calls);
    }

    public function testTurnLimitGivesFinalSummaryAndReport(): void {
        $this->registry->register(new FakeTool('t'));
        $llm = new ScriptedLlm([
            ScriptedLlm::calls(new ToolCall('c1', 't', '{"q":"1"}')),
            ScriptedLlm::calls(new ToolCall('c2', 't', '{"q":"2"}')),
            ScriptedLlm::text('Сделал два шага.'),
        ]);

        $run = $this->execute($llm, $this->start(limits: new RunLimits(2, 100_000, 900, 120)));

        self::assertSame(StopReason::TurnLimit, $run->stopReason);
        self::assertSame(RunStatus::Failed, $run->status);
        self::assertSame([], $llm->requests[2]->tools);
        $messages = self::lastMessages($llm);
        $closing = end($messages);
        self::assertNotFalse($closing);
        self::assertStringContainsString('Лимит исчерпан (исчерпан лимит ходов)', $closing->content);
        self::assertStringStartsWith('Сделал два шага.', (string) $this->observer->final);
        self::assertStringContainsString('Остановлено: исчерпан лимит ходов. Ходов: 3', (string) $this->observer->final);
    }

    public function testHungModelCallEndsAtTheDeadline(): void {
        // A provider that keeps the connection alive but never answers must not hold the run past its deadline.
        $llm = new ScriptedLlm([ScriptedLlm::text('слишком поздно'), ScriptedLlm::text('итог')]);
        $llm->delay = 3.0;
        $started = microtime(true);

        $run = $this->execute($llm, $this->start(limits: new RunLimits(30, 100_000, 1, 120)));

        self::assertSame(StopReason::Deadline, $run->stopReason);
        self::assertLessThan(5.0, microtime(true) - $started, 'the first call is cut at 1 s, the summary takes 3 s');
        self::assertCount(2, $llm->requests);
        self::assertSame([], $llm->requests[1]->tools);
    }

    public function testReadOnlyPolicyRefusesEveryCallThatWrites(): void {
        $write = new FakeTool('w', DangerClass::Write);
        $read = new FakeTool('r');
        $this->registry->register($write);
        $this->registry->register($read);
        $llm = new ScriptedLlm([
            ScriptedLlm::calls(new ToolCall('c1', 'w', '{"q":"1"}'), new ToolCall('c2', 'r', '{"q":"2"}')),
            ScriptedLlm::text('итог'),
        ]);

        $this->execute($llm, $this->start(), readOnly: true);

        self::assertSame([], $write->calls, 'the write never ran');
        self::assertCount(1, $read->calls);
        $refusals = array_filter(self::lastMessages($llm), static fn(Message $m): bool => str_contains($m->content, 'только чтение'));
        self::assertCount(1, $refusals);
    }

    public function testCutOffAnswerIsContinuedAndStitched(): void {
        $llm = new ScriptedLlm([ScriptedLlm::cut('Первая часть, '), ScriptedLlm::text('вторая часть.')]);

        $run = $this->execute($llm, $this->start());

        self::assertSame(StopReason::Answered, $run->stopReason);
        self::assertSame('Первая часть, вторая часть.', $this->observer->final);
        $tail = array_slice($llm->requests[1]->messages, -2);
        self::assertSame(LlmRole::Assistant, $tail[0]->role);
        self::assertSame('Первая часть, ', $tail[0]->content);
        self::assertSame(\Mercurius\I18n\t(AgentLoop::CONTINUE_NOTE), $tail[1]->content);
        $saved = $this->transcript->runMessages($run->id);
        $last = end($saved);
        self::assertNotFalse($last);
        self::assertSame('Первая часть, вторая часть.', $last->content, 'one stitched answer in the history');
    }

    public function testEmptyAnswerIsRetriedWithAHint(): void {
        $llm = new ScriptedLlm([ScriptedLlm::text(''), ScriptedLlm::text('Ответ.')]);

        $run = $this->execute($llm, $this->start());

        self::assertSame(StopReason::Answered, $run->stopReason);
        self::assertSame('Ответ.', $this->observer->final);
        $messages = $llm->requests[1]->messages;
        $last = end($messages);
        self::assertNotFalse($last);
        self::assertSame(\Mercurius\I18n\t(AgentLoop::EMPTY_NOTE), $last->content);
    }

    public function testCutAnswerWithoutTextIsAnEmptyOneNotAContinuation(): void {
        $llm = new ScriptedLlm([ScriptedLlm::cut(''), ScriptedLlm::text('Ответ.')]);

        $this->execute($llm, $this->start());

        self::assertCount(2, $llm->requests);
        $messages = $llm->requests[1]->messages;
        $last = end($messages);
        self::assertNotFalse($last);
        self::assertSame(\Mercurius\I18n\t(AgentLoop::EMPTY_NOTE), $last->content);
    }

    public function testToolStepClearsAFragmentInProgress(): void {
        $this->registry->register(new FakeTool('t'));
        $llm = new ScriptedLlm([
            ScriptedLlm::cut('Начал, '),
            ScriptedLlm::calls(new ToolCall('c1', 't', '{"q":"1"}')),
            ScriptedLlm::text('Готово.'),
        ]);

        $this->execute($llm, $this->start());

        self::assertSame('Готово.', $this->observer->final, 'no stale fragment glued to the answer');
        $messages = $llm->requests[2]->messages;
        $last = end($messages);
        self::assertNotFalse($last);
        self::assertNotSame(\Mercurius\I18n\t(AgentLoop::CONTINUE_NOTE), $last->content);
    }

    public function testEmptyAnswersGiveUpAfterThreeRetries(): void {
        $llm = new ScriptedLlm([ScriptedLlm::text(''), ScriptedLlm::text(''), ScriptedLlm::text(''), ScriptedLlm::text(''), ScriptedLlm::text('лишний')]);

        $this->execute($llm, $this->start());

        self::assertCount(4, $llm->requests);
        self::assertStringContainsString('пустой ответ', (string) $this->observer->final);
    }

    public function testTwoEmptyAnswersWithNoOutputAtAllAreNotAskedAThirdTime(): void {
        $nothing = static fn(): \Mercurius\Llm\ChatResponse => new \Mercurius\Llm\ChatResponse('', [], \Mercurius\Llm\FinishReason::Stop, new \Mercurius\Llm\Usage(100, 0), 'test/model', 'gen');
        $llm = new ScriptedLlm([$nothing(), $nothing(), ScriptedLlm::text('лишний'), ScriptedLlm::text('лишний')]);

        $this->execute($llm, $this->start());

        // ref: hermes empty_response_guard — the same model, the same finish, zero output twice: a deterministic empty
        self::assertCount(2, $llm->requests, 'a third request would bill the same input for the same nothing');
    }

    public function testACostlyEmptyAnswerIsRetriedOnlyOnce(): void {
        $costly = static fn(): \Mercurius\Llm\ChatResponse => new \Mercurius\Llm\ChatResponse('', [], \Mercurius\Llm\FinishReason::Stop, new \Mercurius\Llm\Usage(1000, 5, costUsd: 0.6), 'test/model', 'gen');
        $llm = new ScriptedLlm([$costly(), $costly(), ScriptedLlm::text('лишний')]);

        $this->execute($llm, $this->start());

        self::assertCount(2, $llm->requests);
    }

    public function testAnEmptyAnswerSpentOnReasoningIsAskedAgainWithLessReasoning(): void {
        $thought = new \Mercurius\Llm\ChatResponse('', [], \Mercurius\Llm\FinishReason::Length, new \Mercurius\Llm\Usage(100, 4000, reasoningTokens: 4000), 'test/model', 'gen');
        $llm = new ScriptedLlm([$thought, ScriptedLlm::text('Ответ.'), ScriptedLlm::text('Ещё.')]);

        $this->execute($llm, $this->start());

        self::assertSame('low', $llm->requests[1]->reasoningEffort);
    }

    public function testBrokenStreamIsRetriedOnce(): void {
        $broken = ScriptedLlm::broken('Отв', new \Mercurius\Llm\LlmException(\Mercurius\Llm\ErrorKind::Network, 'reset'));
        $llm = new ScriptedLlm([$broken, ScriptedLlm::text('Ответ.')]);

        $run = $this->execute($llm, $this->start());

        self::assertSame(StopReason::Answered, $run->stopReason);
        self::assertSame('Ответ.', $this->observer->final);
    }

    public function testFailureBeforeTheFirstEventIsNotRetriedAgain(): void {
        // The client retries before the first event itself; the loop does not add another round.
        $llm = new ScriptedLlm([new \Mercurius\Llm\LlmException(\Mercurius\Llm\ErrorKind::Network, 'down'), ScriptedLlm::text('лишний')]);

        $run = $this->execute($llm, $this->start());

        self::assertSame(StopReason::ModelFailed, $run->stopReason);
        self::assertCount(1, $llm->requests);
    }

    public function testFailedModelIsSwitchedOnceToTheMainRole(): void {
        $stores = new \OpenCCK\Test\Support\InMemoryModelStores();
        $roles = new \OpenCCK\App\Agent\Model\ModelRoles($stores, $stores);
        $roles->seed();
        $down = new \Mercurius\Llm\LlmException(\Mercurius\Llm\ErrorKind::Server, 'down');
        $main = (string) $roles->model(\OpenCCK\Domain\Agent\ModelRole::MAIN);
        $llm = new ScriptedLlm([$down]);
        $llm->byModel[$main] = [ScriptedLlm::text('Ответ основной модели.')];
        $loop = new AgentLoop($llm, $this->runs, $this->transcript, $this->conversations, $this->calls, $this->registry, new ToolExecutor(), 100, null, 10,
            fallback: new \OpenCCK\App\Agent\Model\FallbackModels($roles, $stores, $stores, new \Psr\Log\NullLogger()));

        $run = $loop->run($this->start(), new ParticipantPolicy(null, new RunLimits(30, 100_000, 900, 120), null, true), $this->owner, $this->observer, new NullCancellation());

        self::assertSame(StopReason::Answered, $run->stopReason);
        self::assertSame('Ответ основной модели.', $this->observer->final);
        self::assertSame($main, $llm->requests[1]->model);
        self::assertSame('failure', $stores->decisions[0]->method);
    }

    public function testTheSwitchedModelIsKeptInTheRunAndUsedAfterAResume(): void {
        $stores = new \OpenCCK\Test\Support\InMemoryModelStores();
        $roles = new \OpenCCK\App\Agent\Model\ModelRoles($stores, $stores);
        $roles->seed();
        $main = (string) $roles->model(\OpenCCK\Domain\Agent\ModelRole::MAIN);
        $llm = new ScriptedLlm([new \Mercurius\Llm\LlmException(\Mercurius\Llm\ErrorKind::Server, 'down')]);
        $llm->byModel[$main] = [ScriptedLlm::text('ответ')];
        $fallback = new \OpenCCK\App\Agent\Model\FallbackModels($roles, $stores, $stores, new \Psr\Log\NullLogger());
        $loop = new AgentLoop($llm, $this->runs, $this->transcript, $this->conversations, $this->calls, $this->registry, new ToolExecutor(), 100, null, 10, fallback: $fallback);
        $first = $loop->run($this->start(), new ParticipantPolicy(null, new RunLimits(30, 100_000, 900, 120), null, true), $this->owner, $this->observer, new NullCancellation());
        self::assertSame($main, $this->runs->find($first->id)?->fallbackModel);

        // a run that resumes after a switch asks the model it switched to and does not switch twice
        $resumed = $this->start();
        $this->runs->switchModel($resumed->id, $main);
        $again = new ScriptedLlm([]);
        $again->byModel[$main] = [new \Mercurius\Llm\LlmException(\Mercurius\Llm\ErrorKind::Server, 'down too')];
        $loop = new AgentLoop($again, $this->runs, $this->transcript, $this->conversations, $this->calls, $this->registry, new ToolExecutor(), 100, null, 10, fallback: $fallback);
        $run = $loop->run($this->runs->find($resumed->id) ?? $resumed, new ParticipantPolicy(null, new RunLimits(30, 100_000, 900, 120), null, true), $this->owner, $this->observer, new NullCancellation());

        self::assertSame($main, $again->requests[0]->model);
        self::assertSame(StopReason::ModelFailed, $run->stopReason);
        self::assertCount(1, $again->requests, 'one switch per run, across a resume too');
    }

    public function testARefusalOfTheContentFilterDoesNotSwitchTheModel(): void {
        $stores = new \OpenCCK\Test\Support\InMemoryModelStores();
        $roles = new \OpenCCK\App\Agent\Model\ModelRoles($stores, $stores);
        $roles->seed();
        $main = (string) $roles->model(\OpenCCK\Domain\Agent\ModelRole::MAIN);
        $llm = new ScriptedLlm([new \Mercurius\Llm\LlmException(\Mercurius\Llm\ErrorKind::ContentFilter, 'refused')]);
        $llm->byModel[$main] = [ScriptedLlm::text('ответ другой модели')];
        $loop = new AgentLoop($llm, $this->runs, $this->transcript, $this->conversations, $this->calls, $this->registry, new ToolExecutor(), 100, null, 10,
            fallback: new \OpenCCK\App\Agent\Model\FallbackModels($roles, $stores, $stores, new \Psr\Log\NullLogger()));

        $run = $loop->run($this->start(), new ParticipantPolicy(null, new RunLimits(30, 100_000, 900, 120), null, true), $this->owner, $this->observer, new NullCancellation());

        // spec: brief/agent-loop.md#запасная-модель-и-ограничитель-зацикливания — a content filter is a verdict on the request, not a broken model
        self::assertSame(StopReason::ModelFailed, $run->stopReason);
        self::assertCount(1, $llm->requests, 'not asked again of another model');
    }

    public function testSpendCapStopsBeforeTheNextModelCall(): void {
        $this->registry->register(new FakeTool('t'));
        $llm = new ScriptedLlm([ScriptedLlm::calls(new ToolCall('c1', 't', '{"q":"1"}')), ScriptedLlm::text('не должно быть')]);
        // Every call cost $1; the day allows $1.
        $this->calls->record(new \OpenCCK\Domain\Entity\LlmCallRecord(null, 'agent', 'm', 'stop', null, 1, 1, 0, 0, 0, 1.0, 10));
        $guard = new \OpenCCK\App\Agent\SpendGuard($this->calls, 1.0, 0.0, new \Psr\Log\NullLogger());
        $loop = new AgentLoop($llm, $this->runs, $this->transcript, $this->conversations, $this->calls, $this->registry, new ToolExecutor(), 100, null, 10, spend: $guard);

        $run = $loop->run($this->start(), new ParticipantPolicy(null, new RunLimits(30, 100_000, 900, 120), null, true), $this->owner, $this->observer, new NullCancellation());

        self::assertSame(StopReason::SpendCap, $run->stopReason);
        self::assertSame([], $llm->requests, 'no call over the cap');
        self::assertSame(0, $run->tokensReserved);
        self::assertStringContainsString('лимит расхода', (string) $this->observer->final);
    }

    public function testAParticipantsOwnDailyCapStopsOnlyTheirRuns(): void {
        $this->registry->register(new FakeTool('t'));
        $llm = new ScriptedLlm([ScriptedLlm::text('ответ владельцу')]);
        // $0.6 spent today in conversations of participant 5; their cap is $0.5, the agent has no cap.
        $this->calls->spentBy[5] = 0.6;
        $guard = new \OpenCCK\App\Agent\SpendGuard($this->calls, 0.0, 0.0, new \Psr\Log\NullLogger());
        $loop = new AgentLoop($llm, $this->runs, $this->transcript, $this->conversations, $this->calls, $this->registry, new ToolExecutor(), 100, null, 10, spend: $guard);
        $capped = ParticipantPolicy::fromArray(['daily_spend_usd' => 0.5], new ParticipantPolicy(null, new RunLimits(30, 100_000, 900, 120), null, true));
        self::assertSame(0.5, $capped->dailySpendUsd);
        self::assertSame(0.5, $capped->capTo(new ParticipantPolicy(null, new RunLimits(30, 100_000, 900, 120), null, true))->dailySpendUsd, 'the owner has no cap of their own');
        self::assertSame(0.5, $capped->toArray()['daily_spend_usd']);

        $user = new \OpenCCK\Domain\Entity\Member(5, \OpenCCK\Domain\Entity\MemberKind::Human, \OpenCCK\Domain\Entity\Role::user(), 'Вася');
        $conversation = $this->conversations->openConversation(5, 'telegram', '5');
        $run = $this->runs->start($conversation, 5, 'm', new RunLimits(30, 100_000, 900, 120));
        self::assertNotNull($run);
        $stopped = $loop->run($run, $capped, $user, $this->observer, new NullCancellation());
        self::assertSame(StopReason::SpendCap, $stopped->stopReason);
        self::assertSame([], $llm->requests);

        $owners = $loop->run($this->start(), new ParticipantPolicy(null, new RunLimits(30, 100_000, 900, 120), null, true), $this->owner, $this->observer, new NullCancellation());
        self::assertSame(StopReason::Answered, $owners->stopReason, 'the cap of one participant does not stop others');
    }

    public function testTokenBudgetStopsBeforeModelCall(): void {
        $llm = new ScriptedLlm([ScriptedLlm::text('итог')]);

        $run = $this->execute($llm, $this->start(limits: new RunLimits(30, 50, 900, 120)), maxTokens: 100);

        self::assertSame(StopReason::TokenBudget, $run->stopReason);
        self::assertCount(1, $llm->requests);
        self::assertSame([], $llm->requests[0]->tools);
    }

    public function testEightyPercentWarningIsAddedOnce(): void {
        $this->registry->register(new FakeTool('t'));
        $script = [];
        for ($i = 1; $i <= 4; $i++) {
            $script[] = ScriptedLlm::calls(new ToolCall('c' . $i, 't', '{"q":"' . $i . '"}'));
        }
        $script[] = ScriptedLlm::text('итог');
        $llm = new ScriptedLlm($script);

        $run = $this->execute($llm, $this->start(limits: new RunLimits(5, 100_000, 900, 120)));

        self::assertSame(RunStatus::Done, $run->status);
        $warnings = array_filter(self::lastMessages($llm), static fn(Message $m) => str_contains($m->content, 'Подводи итог'));
        self::assertCount(1, $warnings);
    }

    public function testLoopGuardHaltsOnFifthRepeat(): void {
        // a tool that watches the outside world stays offered after the warning: only the fifth repeat stops the run
        $this->registry->register(new FakeTool('web_read'));
        $script = [];
        for ($i = 1; $i <= 5; $i++) {
            $script[] = ScriptedLlm::calls(new ToolCall('c' . $i, 'web_read', '{"q":"same"}'));
        }
        $script[] = ScriptedLlm::text('Успел узнать: ответ тот же.');
        $llm = new ScriptedLlm($script);

        $run = $this->execute($llm, $this->start());

        self::assertSame(StopReason::LoopGuard, $run->stopReason);
        // spec: brief/agent-loop.md#запасная-модель-и-ограничитель-зацикливания — five turns of the repeat, then one call
        //       without tools: a run stopped for repeating itself still tells what it found
        self::assertSame(6, $run->turns);
        self::assertSame([], $llm->requests[5]->tools);
        self::assertStringContainsString('Успел узнать', $this->observer->final ?? '');
    }

    public function testAToolRepeatedForTheSameAnswerIsNotOfferedAgain(): void {
        // spec: brief/agent-loop.md#запасная-модель-и-ограничитель-зацикливания
        $this->registry->register(new FakeTool('t'));
        $this->registry->register(new FakeTool('other'));
        $script = [];
        for ($i = 1; $i <= 3; $i++) {
            $script[] = ScriptedLlm::calls(new ToolCall('c' . $i, 't', '{"q":"same"}'));
        }
        $script[] = ScriptedLlm::text('Отвечаю по тому, что есть.');
        $llm = new ScriptedLlm($script);

        $run = $this->execute($llm, $this->start());

        self::assertSame(StopReason::Answered, $run->stopReason);
        $offered = static fn(int $call): array => array_map(static fn(\Mercurius\Llm\ToolDefinition $tool): string => $tool->name, $llm->requests[$call]->tools);
        self::assertContains('t', $offered(2), 'two repeats are not a loop yet');
        self::assertNotContains('t', $offered(3), 'the third one takes the tool away');
        self::assertContains('other', $offered(3), 'the other tools stay');
    }

    /**
     * spec: brief/agent-loop.md#запасная-модель-и-ограничитель-зацикливания — a model that calls a tool it is no longer
     * offered has not heard the warning: the call is not run and the run stops at once
     */
    public function testACallToAToolNoLongerOfferedStopsTheRunWithoutRunningIt(): void {
        $tool = new FakeTool('t');
        $this->registry->register($tool);
        $script = [];
        for ($i = 1; $i <= 4; $i++) {
            $script[] = ScriptedLlm::calls(new ToolCall('c' . $i, 't', '{"q":"same"}'));
        }
        $script[] = ScriptedLlm::text('Итог по тому, что есть.');
        $llm = new ScriptedLlm($script);

        $run = $this->execute($llm, $this->start());

        self::assertSame(StopReason::LoopGuard, $run->stopReason);
        self::assertCount(3, $tool->calls, 'the fourth call, to a tool taken away, does not run');
        self::assertSame(5, $run->turns, 'four turns of the repeat and the closing answer: the run does not wait for a fifth repeat');
    }

    public function testTheSameCallWrittenSeveralTimesInOneAnswerIsNotALoop(): void {
        // spec: brief/agent-loop.md#запасная-модель-и-ограничитель-зацикливания — a model that writes one call five times in an
        //       answer slipped once: the call runs once, the copies are answered, the run goes on
        $tool = new FakeTool('t');
        $this->registry->register($tool);
        $copies = array_map(static fn(int $i): ToolCall => new ToolCall('c' . $i, 't', '{"q":"same"}'), range(1, 6));
        $llm = new ScriptedLlm([ScriptedLlm::calls(...$copies), ScriptedLlm::text('Готово.')]);

        $run = $this->execute($llm, $this->start());

        self::assertSame(StopReason::Answered, $run->stopReason);
        self::assertSame(2, $run->turns);
    }

    public function testTheLoopGuardOfAResumedRunRemembersTheRepeatsBefore(): void {
        $this->registry->register(new FakeTool('t'));
        $first = new ScriptedLlm([ScriptedLlm::calls(new ToolCall('c1', 't', '{"q":"same"}')), ScriptedLlm::calls(new ToolCall('c2', 't', '{"q":"same"}')), ScriptedLlm::text('стоп')]);
        $run = $this->execute($first, $this->start());
        $kept = $this->runs->find($run->id);
        self::assertNotNull($kept);
        self::assertCount(2, $kept->loopHistory);

        $resumed = $this->start();
        $this->runs->saveLoopHistory($resumed->id, $kept->loopHistory);
        $script = [];
        for ($i = 1; $i <= 4; $i++) {
            $script[] = ScriptedLlm::calls(new ToolCall('r' . $i, 't', '{"q":"same"}'));
        }
        $again = $this->execute(new ScriptedLlm($script), $this->runs->find($resumed->id) ?? $resumed);

        self::assertSame(StopReason::LoopGuard, $again->stopReason);
        self::assertSame(3, $again->turns, 'two repeats before the restart count: the third takes the tool away, the call after it stops the run, then the closing answer');
    }

    public function testInboxMessageIsDeliveredAfterToolResults(): void {
        $runRef = $this->start();
        $runId = $runRef->id;
        $this->registry->register(new FakeTool('t', handler: function () use ($runId): ToolResult {
            $this->transcript->inbox($runId, 'и ещё проверь X');
            return ToolResult::ok('r');
        }));
        $llm = new ScriptedLlm([ScriptedLlm::calls(new ToolCall('c1', 't', '{}')), ScriptedLlm::text('ок')]);

        $this->execute($llm, $runRef);

        $messages = self::lastMessages($llm);
        self::assertSame(LlmRole::Tool, $messages[3]->role);
        self::assertSame(LlmRole::User, $messages[4]->role);
        self::assertSame('и ещё проверь X', $messages[4]->content);
    }

    public function testShutdownLeavesRunRunning(): void {
        $this->registry->register(new FakeTool('slow', delay: 1.0));
        $llm = new ScriptedLlm([ScriptedLlm::calls(new ToolCall('c1', 'slow', '{}'))]);
        $deferred = new DeferredCancellation();
        $run = $this->start();
        $future = async(fn(): Run => $this->execute($llm, $run, cancellation: $deferred->getCancellation()));
        delay(0.1);
        $deferred->cancel(new ShutdownException());

        $result = $future->await();
        self::assertInstanceOf(Run::class, $result);

        self::assertSame(RunStatus::Running, $result->status);
        self::assertNull($this->observer->final);
    }

    public function testStopMarksRunStoppedAndCallInterrupted(): void {
        $this->registry->register(new FakeTool('slow', delay: 1.0));
        $llm = new ScriptedLlm([ScriptedLlm::calls(new ToolCall('c1', 'slow', '{}'))]);
        $deferred = new DeferredCancellation();
        $run = $this->start();
        $future = async(fn(): Run => $this->execute($llm, $run, cancellation: $deferred->getCancellation()));
        delay(0.1);
        $deferred->cancel();

        $result = $future->await();
        self::assertInstanceOf(Run::class, $result);

        self::assertSame(RunStatus::Stopped, $result->status);
        self::assertSame(StopReason::Stopped, $result->stopReason);
        self::assertSame(\OpenCCK\Domain\Agent\ToolCallStatus::Interrupted, $this->transcript->status($run->id, 'c1'));
    }

    public function testReadCallsRunInParallel(): void {
        $this->registry->register(new FakeTool('a', delay: 1.0));
        $this->registry->register(new FakeTool('b', delay: 1.0));
        $llm = new ScriptedLlm([ScriptedLlm::calls(new ToolCall('c1', 'a', '{}'), new ToolCall('c2', 'b', '{}')), ScriptedLlm::text('ок')]);

        $started = microtime(true);
        $this->execute($llm, $this->start());

        self::assertLessThan(1.5, microtime(true) - $started);
    }

    public function testPendingCallsAreExecutedFirstOnResume(): void {
        $tool = new FakeTool('t');
        $this->registry->register($tool);
        $run = $this->start();
        $this->transcript->appendAssistant($this->conversationId, $run->id, '', [
            new \OpenCCK\Domain\Agent\StoredToolCall('c1', 0, 't', ['q' => 'x'], '{"q":"x"}', DangerClass::Read, \OpenCCK\Domain\Agent\ToolCallStatus::Pending),
        ]);
        $llm = new ScriptedLlm([ScriptedLlm::text('ок')]);

        $result = $this->execute($llm, $run);

        self::assertSame(RunStatus::Done, $result->status);
        self::assertSame([['q' => 'x']], $tool->calls);
        self::assertSame(LlmRole::Tool, $llm->requests[0]->messages[3]->role);
    }

    public function testEightBigReadsOfOneTurnFitAThirdOfTheWindow(): void {
        $calls = [];
        for ($i = 1; $i <= 8; $i++) {
            $calls[] = new ToolCall('r' . $i, 'read', '{"q":"' . $i . '"}');
        }
        $stack = new \OpenCCK\Test\Support\AgentStack(new ScriptedLlm([ScriptedLlm::calls(...$calls), ScriptedLlm::text('Прочитал.')]), budget: \OpenCCK\App\Agent\Tool\ResultBudget::forWindow(4_000));
        $stack->registry->register(new \OpenCCK\Test\Support\FakeTool('read', handler: static fn(array $args): \OpenCCK\App\Agent\Tool\ToolResult => \OpenCCK\App\Agent\Tool\ToolResult::ok(str_repeat('строка файла ', 1_500))));

        $stack->say('прочитай восемь файлов');
        $stack->scheduler->awaitIdle();

        // spec: brief/agent-loop.md#контекст — the results of a turn take at most 30% of the window
        $tools = array_filter($stack->llm->requests[1]->messages, static fn($m): bool => $m->role === \Mercurius\Llm\Role::Tool);
        self::assertCount(8, $tools);
        self::assertLessThanOrEqual(16_000, array_sum(array_map(static fn($m): int => mb_strlen($m->content), $tools)));
    }

    public function testASecondIdenticalResultIsAReferenceToTheFirst(): void {
        $stack = new \OpenCCK\Test\Support\AgentStack(new ScriptedLlm([
            ScriptedLlm::calls(new ToolCall('r1', 'read', '{"q":"1"}')),
            ScriptedLlm::calls(new ToolCall('r2', 'read', '{"q":"2"}')),
            ScriptedLlm::text('Готово.'),
        ]));
        $stack->registry->register(new \OpenCCK\Test\Support\FakeTool('read', handler: static fn(array $args): \OpenCCK\App\Agent\Tool\ToolResult => \OpenCCK\App\Agent\Tool\ToolResult::ok(str_repeat('тот же длинный текст ', 100))));

        $stack->say('прочитай дважды');
        $stack->scheduler->awaitIdle();

        // spec: brief/agent-loop.md#контекст — the same long result again is a reference, not a copy
        $tools = array_values(array_filter($stack->llm->requests[2]->messages, static fn($m): bool => $m->role === \Mercurius\Llm\Role::Tool));
        self::assertCount(2, $tools);
        self::assertStringContainsString('тот же длинный текст', $tools[0]->content);
        self::assertStringNotContainsString('тот же длинный текст', $tools[1]->content);
        self::assertStringContainsString('r1', $tools[1]->content, 'the reference names the first call');
    }
}
