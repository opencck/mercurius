<?php

declare(strict_types=1);

namespace OpenCCK\Test\Unit\App\Agent\Delegation;

use Amp\PHPUnit\AsyncTestCase;
use Mercurius\Llm\ErrorKind;
use Mercurius\Llm\LlmException;
use Mercurius\Llm\Role;
use Mercurius\Llm\ToolCall;
use OpenCCK\App\Agent\Delegation\DelegateTaskTool;
use OpenCCK\App\Agent\Delegation\WorkerRunner;
use Mercurius\Llm\ModelCatalog\ModelInfo;
use OpenCCK\App\Agent\Model\ModelRoles;
use OpenCCK\App\Agent\Model\ModelSelector;
use OpenCCK\Domain\Agent\DangerClass;
use OpenCCK\Domain\Agent\ModelRole;
use OpenCCK\Domain\Agent\RunLimits;
use OpenCCK\Domain\Agent\RunStatus;
use OpenCCK\Test\Support\AgentStack;
use OpenCCK\Test\Support\FakeKeyedTool;
use OpenCCK\Test\Support\FakeTool;
use OpenCCK\Test\Support\InMemoryExperienceRepository;
use OpenCCK\Test\Support\InMemoryModelStores;
use OpenCCK\Test\Support\ScriptedLlm;
use Psr\Log\NullLogger;

use function Amp\delay;

/**
 * Delegation: workers are nested runs in their own conversations, on the model of their role, within the
 * orchestrator's budget; the orchestrator gets a short report.
 */
final class DelegateTaskToolTest extends AsyncTestCase {
    private WorkerRunner $runner;
    private ModelRoles $roles;
    private InMemoryModelStores $stores;
    private InMemoryExperienceRepository $experience;

    private function stack(ScriptedLlm $llm, RunLimits $limits = new RunLimits(30, 1_000_000, 900, 120)): AgentStack {
        $stores = $this->stores = new InMemoryModelStores();
        $stores->replace('openrouter', [
            new ModelInfo('a/cheap', 'cheap', 200_000, 0.1 / 1e6, 0.6 / 1e6, ['text'], ['text'], ['tools'], null, null, null, 24.0, 71.0, 42.0),
            new ModelInfo('a/agent', 'agent', 200_000, 0.15 / 1e6, 0.5 / 1e6, ['text'], ['text'], ['tools'], null, null, null, 28.0, 71.0, 51.0),
            new ModelInfo('a/top', 'top', 200_000, 5.0 / 1e6, 25.0 / 1e6, ['text'], ['text'], ['tools'], null, null, null, 44.0, 78.0, 57.0),
        ]);
        $this->roles = new ModelRoles($stores, $stores);
        $this->roles->seed();
        $stack = new AgentStack($llm, $limits);
        $stack->registry->register(new FakeTool('read', delay: 0.2));
        $stack->registry->register(new FakeTool('push', DangerClass::Irreversible));
        $stack->registry->register(new FakeTool('note', DangerClass::Write));
        $stack->registry->register(new FakeTool('memory_search'));
        $stack->registry->register(new FakeTool('memory_propose', DangerClass::Write));
        $runner = new WorkerRunner(
            $stack->loop, $stack->runs, $stack->transcript, $stack->conversations, $stack->registry, $this->roles, 15, 100_000, new NullLogger(),
            new ModelSelector($stores, $this->roles, $stores, new NullLogger()),
        );
        $runner->useExperience($this->experience = new InMemoryExperienceRepository());
        $this->runner = $runner;
        $stack->registry->register(new DelegateTaskTool($runner));
        return $stack;
    }

    /** @param list<array<string, mixed>> $tasks */
    private static function delegate(array $tasks, string $id = 'd1'): \Mercurius\Llm\ChatResponse {
        return ScriptedLlm::calls(new ToolCall($id, 'delegate_task', json_encode(['tasks' => $tasks], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)));
    }

    /** The tool result the orchestrator got back, from its last request. */
    private static function report(ScriptedLlm $llm): string {
        foreach (array_reverse($llm->requests) as $request) {
            foreach ($request->messages as $message) {
                if ($message->role === Role::Tool && str_contains($message->content, 'Задача 1')) {
                    return $message->content;
                }
            }
        }
        return '';
    }

    public function testWorkerAnswersAndTheOrchestratorGetsTheReport(): void {
        $llm = new ScriptedLlm([
            self::delegate([['goal' => 'посчитай 6 × 7', 'context' => 'проверка', 'output' => 'число']]),
            ScriptedLlm::text('Ответ воркера: 42'),
            ScriptedLlm::text('Итог: 42'),
        ]);
        $stack = $this->stack($llm);
        $events = new \OpenCCK\Test\Support\RecordingEvents();
        $this->runner->useEvents($events);

        $stack->say('реши задачу');
        $stack->scheduler->awaitIdle();

        self::assertSame(RunStatus::Done, $stack->runs->find(1)?->status);
        // spec: brief/operations.md#шаги-и-хранение — the worker is a step of its orchestrator's trace
        self::assertContains('worker:1:2:done', $events->events);
        $worker = $stack->runs->find(2);
        self::assertNotNull($worker);
        self::assertSame(1, $worker->parentRunId);
        self::assertNotSame(1, $worker->conversationId);
        self::assertSame((string) $this->roles->model(ModelRole::MAIN), $llm->requests[1]->model);
        self::assertStringContainsString('посчитай 6 × 7', $llm->requests[1]->messages[1]->content);
        $report = self::report($llm);
        self::assertStringContainsString('done', $report);
        self::assertStringContainsString('Ответ воркера: 42', $report);
        self::assertStringContainsString('#2', $report);
        foreach ($stack->transcript->runMessages(1) as $message) {
            self::assertStringNotContainsString('Ответ воркера', $message->role === Role::Tool ? '' : $message->content);
        }
    }

    public function testWorkerGetsOnlyAllowedTools(): void {
        $llm = new ScriptedLlm([
            self::delegate([['goal' => 'прочитай', 'tools' => ['read', 'delegate_task', 'set_model_role', 'nope']]]),
            ScriptedLlm::text('готово'),
            ScriptedLlm::text('итог'),
        ]);
        $stack = $this->stack($llm);

        $stack->say('задача');
        $stack->scheduler->awaitIdle();

        self::assertSame(['read'], array_map(static fn($t): string => $t->name, $llm->requests[1]->tools));
    }

    public function testTheOrchestratorSetsAReadOnlyModeAndTheBriefShowsTheRights(): void {
        // spec: brief/orchestration.md#права-воркера-задаёт-оркестратор
        $llm = new ScriptedLlm([
            self::delegate([['goal' => 'прочитай', 'tools' => ['read', 'note'], 'mode' => 'read']]),
            ScriptedLlm::text('готово'),
            ScriptedLlm::text('итог'),
        ]);
        $stack = $this->stack($llm);

        $stack->say('задача');
        $stack->scheduler->awaitIdle();

        self::assertSame(['read'], array_map(static fn($t): string => $t->name, $llm->requests[1]->tools), 'a write tool is dropped in the read mode');
        $brief = $llm->requests[1]->messages[1]->content;
        self::assertStringContainsString('read', $brief);
        self::assertStringContainsString(\Mercurius\I18n\t('delegation.worker_runner.rights_read'), $brief, 'the worker and the trace see its rights');
    }

    public function testMemoryIsGivenOnlyWhenTheOrchestratorSaysSo(): void {
        // spec: brief/orchestration.md#права-воркера-задаёт-оркестратор — memory is the orchestrator's decision
        $llm = new ScriptedLlm([
            self::delegate([
                ['goal' => 'без памяти', 'tools' => ['read', 'memory_search']],
                ['goal' => 'читает память', 'tools' => ['read'], 'memory' => 'read'],
                ['goal' => 'пишет в память', 'tools' => ['note'], 'memory' => 'write', 'mode' => 'write'],
                ['goal' => 'чтение не пишет', 'tools' => ['read'], 'memory' => 'write', 'mode' => 'read'],
            ]),
            ScriptedLlm::text('1'), ScriptedLlm::text('2'), ScriptedLlm::text('3'), ScriptedLlm::text('4'),
            ScriptedLlm::text('итог'),
        ]);
        $stack = $this->stack($llm);

        $stack->say('задача');
        $stack->scheduler->awaitIdle();

        $tools = [];
        foreach (array_slice($llm->requests, 1, 4) as $request) {
            $first = $request->messages[1]->content;
            $names = array_map(static fn($t): string => $t->name, $request->tools);
            sort($names);
            $tools[str_contains($first, 'без памяти') ? 1 : (str_contains($first, 'читает') ? 2 : (str_contains($first, 'пишет в') ? 3 : 4))] = $names;
        }
        ksort($tools);
        self::assertSame([
            1 => ['read'],
            2 => ['memory_search', 'read'],
            3 => ['memory_propose', 'memory_search', 'note'],
            4 => ['memory_search', 'read'],
        ], $tools);
    }

    public function testTheContextOfTheConversationIsGivenOnlyOnRequest(): void {
        // spec: brief/orchestration.md#права-воркера-задаёт-оркестратор — isolation unless the orchestrator asks for context
        $llm = new ScriptedLlm([
            self::delegate([['goal' => 'изолирован'], ['goal' => 'с проектом', 'context_of' => ['project']]]),
            ScriptedLlm::text('1'), ScriptedLlm::text('2'),
            ScriptedLlm::text('итог'),
        ]);
        $stack = $this->stack($llm);
        $asked = [];
        $this->runner->useContext(static function (\OpenCCK\App\Agent\Tool\ToolContext $parent, array $of) use (&$asked): string {
            $asked[] = $of;
            return in_array('project', $of, true) ? 'КАРТОЧКА ПРОЕКТА' : '';
        });

        $stack->say('задача');
        $stack->scheduler->awaitIdle();

        $briefs = array_map(static fn($r): string => $r->messages[1]->content, array_slice($llm->requests, 1, 2));
        $isolated = array_values(array_filter($briefs, static fn(string $b): bool => str_contains($b, 'изолирован')))[0] ?? '';
        $withProject = array_values(array_filter($briefs, static fn(string $b): bool => str_contains($b, 'с проектом')))[0] ?? '';
        self::assertStringNotContainsString('КАРТОЧКА ПРОЕКТА', $isolated);
        self::assertStringContainsString('КАРТОЧКА ПРОЕКТА', $withProject);
        self::assertSame([['project']], $asked, 'nothing is read for an isolated part');
    }

    public function testAnAnswerNotByTheSchemaGetsOneCorrectionTurn(): void {
        // spec: brief/orchestration.md#схема-ответа-воркера
        $schema = ['type' => 'object', 'properties' => ['n' => ['type' => 'integer']], 'required' => ['n'], 'additionalProperties' => false];
        $llm = new ScriptedLlm([
            self::delegate([['goal' => 'посчитай 6 × 7', 'output_schema' => $schema]]),
            ScriptedLlm::text('Сорок два.'),
            ScriptedLlm::text("```json\n{\"n\": 42}\n```"),
            ScriptedLlm::text('итог'),
        ]);
        $stack = $this->stack($llm);

        $stack->say('задача');
        $stack->scheduler->awaitIdle();

        self::assertStringContainsString('"required"', $llm->requests[1]->messages[1]->content, 'the contract comes with the task');
        $correction = array_slice($llm->requests[2]->messages, -1)[0] ?? null;
        self::assertNotNull($correction);
        self::assertStringContainsString(\Mercurius\I18n\t('delegation.output_contract.rejected'), $correction->content, 'the same worker gets the errors back');
        self::assertStringContainsString('Сорок два.', implode("\n", array_map(static fn($m): string => $m->content, $llm->requests[2]->messages)), 'it continues its own conversation');
        $report = self::report($llm);
        self::assertStringContainsString(\Mercurius\I18n\t('delegation.worker_reports.schema_ok'), $report);
        self::assertStringContainsString('"n": 42', $report);
    }

    public function testAnAnswerStillNotByTheSchemaIsReportedSo(): void {
        $schema = ['type' => 'object', 'properties' => ['n' => ['type' => 'integer']], 'required' => ['n']];
        $llm = new ScriptedLlm([
            self::delegate([['goal' => 'посчитай', 'output_schema' => $schema]]),
            ScriptedLlm::text('не знаю'),
            ScriptedLlm::text('{"n": "сорок два"}'),
            ScriptedLlm::text('итог'),
        ]);
        $stack = $this->stack($llm);

        $stack->say('задача');
        $stack->scheduler->awaitIdle();

        $report = self::report($llm);
        self::assertStringContainsString(\Mercurius\I18n\t('delegation.worker_reports.schema_failed'), $report, 'handed over, but marked');
        self::assertCount(4, $llm->requests, 'one correction turn, not more');
    }

    public function testASchemaThatIsNotAnObjectAndACheckedPartWithoutAnExpectedAnswerAreRefused(): void {
        $llm = new ScriptedLlm([
            self::delegate([['goal' => 'раз', 'output_schema' => 'строка']]),
            self::delegate([['goal' => 'два', 'verify' => true]], 'd2'),
            ScriptedLlm::text('итог'),
        ]);
        $stack = $this->stack($llm);

        $stack->say('задача');
        $stack->scheduler->awaitIdle();

        $results = [];
        foreach ($llm->requests[2]->messages as $message) {
            if ($message->role === Role::Tool) {
                $results[] = $message->content;
            }
        }
        self::assertCount(2, $results);
        self::assertStringContainsString('output_schema', $results[0]);
        self::assertStringContainsString('output', $results[1], 'a verifier needs to know what answer was asked for');
        self::assertNull($stack->runs->find(2), 'no worker started');
    }

    public function testAReportGetsTheRoomLeftInTheOrchestratorsWindow(): void {
        // spec: brief/orchestration.md#часть-1--синхронное-делегирование — the report share follows the room left
        $llm = new ScriptedLlm([
            self::delegate([['goal' => 'длинный ответ']]),
            ScriptedLlm::text(str_repeat('x', 5000)),
            ScriptedLlm::text('итог'),
        ]);
        $stack = $this->stack($llm);
        $this->runner->useWindow(static fn(string $model): int => 1000);

        $stack->say('задача');
        $stack->scheduler->awaitIdle();

        self::assertSame(1200, substr_count(self::report($llm), 'x'), 'a window of 1000 tokens leaves 30% of 4000 characters');
    }

    public function testAWorkerGetsOnlyToolsOfTheWhiteList(): void {
        $llm = new ScriptedLlm([
            self::delegate([['goal' => 'прочитай', 'tools' => ['read', 'push']]]),
            ScriptedLlm::text('готово'),
            ScriptedLlm::text('итог'),
        ]);
        $stack = $this->stack($llm);
        // spec: brief/orchestration.md#белый-список-инструментов-воркера — a worker gets only tools of the white list, a new one is closed
        $this->runner->allowOnly(['read', 'mcp__*']);
        self::assertSame(['read', 'mcp__mail__send'], $this->runner->onTheList(['read', 'push', 'mcp__mail__send']));

        $stack->say('задача');
        $stack->scheduler->awaitIdle();

        self::assertSame(['read'], array_map(static fn($t): string => $t->name, $llm->requests[1]->tools));
    }

    public function testWorkerCannotAskTheOwner(): void {
        $llm = new ScriptedLlm([
            self::delegate([['goal' => 'отправь', 'tools' => ['push']]]),
            ScriptedLlm::calls(new ToolCall('p1', 'push', '{}')),
            ScriptedLlm::text('не вышло'),
            ScriptedLlm::text('итог'),
        ]);
        $stack = $this->stack($llm);

        $stack->say('задача');
        $stack->scheduler->awaitIdle();

        self::assertSame([], array_filter($stack->channel->texts('send'), static fn(string $t): bool => str_starts_with($t, 'Подтвердите')));
        self::assertSame(RunStatus::Done, $stack->runs->find(1)?->status);
        self::assertStringContainsString('необратимые действия недоступны', $llm->requests[2]->messages[3]->content);
    }

    public function testNotEnoughBudgetRefuses(): void {
        $llm = new ScriptedLlm([
            self::delegate([['goal' => 'раз'], ['goal' => 'два']]),
            ScriptedLlm::text('итог'),
        ]);
        $stack = $this->stack($llm, new RunLimits(30, 3000, 900, 120));

        $stack->say('задача');
        $stack->scheduler->awaitIdle();

        self::assertNull($stack->runs->find(2));
        self::assertStringContainsString('бюджет', $llm->requests[1]->messages[3]->content);
    }

    public function testReadOnlyTasksRunInParallel(): void {
        $llm = new ScriptedLlm([
            self::delegate([['goal' => 'первое', 'tools' => ['read']], ['goal' => 'второе', 'tools' => ['read']]]),
            ScriptedLlm::calls(new ToolCall('r1', 'read', '{}')),
            ScriptedLlm::calls(new ToolCall('r2', 'read', '{}')),
            ScriptedLlm::text('а'),
            ScriptedLlm::text('б'),
            ScriptedLlm::text('итог'),
        ]);
        $stack = $this->stack($llm);

        $started = microtime(true);
        $stack->say('задача');
        $stack->scheduler->awaitIdle();

        self::assertLessThan(0.38, microtime(true) - $started);
        self::assertSame(RunStatus::Done, $stack->runs->find(1)?->status);
    }

    public function testFailedWorkerIsAValueNotAnError(): void {
        $down = new LlmException(ErrorKind::Server, 'down');
        // On the strong role there is nothing stronger to retry on: one failure is the whole story.
        $llm = new ScriptedLlm([self::delegate([['goal' => 'раз', 'role' => 'strong']]), $down, ScriptedLlm::text('итог без воркера')]);
        $stack = $this->stack($llm);

        $stack->say('задача');
        $stack->scheduler->awaitIdle();

        self::assertSame(RunStatus::Done, $stack->runs->find(1)?->status);
        self::assertStringContainsString('failed', self::report($llm));
        self::assertNull($stack->runs->find(3), 'no retry on the same model');
    }

    public function testProfileAskingForNothingKeepsTheRole(): void {
        $llm = new ScriptedLlm([self::delegate([['goal' => 'раз', 'role' => 'cheap', 'profile' => ['error_cost' => 9]]]), ScriptedLlm::text('готово'), ScriptedLlm::text('итог')]);
        $stack = $this->stack($llm);

        $stack->say('задача');
        $stack->scheduler->awaitIdle();

        self::assertSame((string) $this->roles->model(ModelRole::CHEAP), $llm->requests[1]->model);
        self::assertSame([], $this->stores->decisions);
    }

    public function testLimitStopIsNotRetried(): void {
        // A worker that keeps calling a tool runs into its turn limit: that is the task, not the model.
        $calls = [self::delegate([['goal' => 'раз', 'role' => 'cheap', 'tools' => ['read']]])];
        for ($i = 0; $i < 20; $i++) {
            $calls[] = ScriptedLlm::calls(new ToolCall('r' . $i, 'read', json_encode(['path' => 'f' . $i], JSON_THROW_ON_ERROR)));
        }
        $calls[] = ScriptedLlm::text('итог воркера');
        $calls[] = ScriptedLlm::text('итог');
        $llm = new ScriptedLlm($calls);
        $stack = $this->stack($llm);

        $stack->say('задача');
        $stack->scheduler->awaitIdle();

        self::assertNull($stack->runs->find(3));
        self::assertStringContainsString('limit', self::report($llm));
        self::assertStringNotContainsString('каскад', self::report($llm));
    }

    public function testStopStopsTheWorker(): void {
        $llm = new ScriptedLlm([
            self::delegate([['goal' => 'долго', 'tools' => ['read']]]),
            ScriptedLlm::calls(new ToolCall('r1', 'read', '{}')),
            ScriptedLlm::text('не дойдёт'),
        ]);
        $llm->delay = 0.3;
        $stack = $this->stack($llm);

        $stack->say('задача');
        delay(0.45);
        $stack->say('/stop');
        $stack->scheduler->awaitIdle();

        self::assertSame(RunStatus::Stopped, $stack->runs->find(1)?->status);
        self::assertSame(RunStatus::Stopped, $stack->runs->find(2)?->status);
        self::assertNull($stack->runs->find(3), 'a stopped worker is not retried');
    }

    public function testProfileChoosesTheModel(): void {
        $llm = new ScriptedLlm([self::delegate([['goal' => 'много шагов с инструментами', 'tools' => ['read'], 'profile' => ['agency' => 6]]]), ScriptedLlm::text('готово'), ScriptedLlm::text('итог')]);
        $stack = $this->stack($llm);

        $stack->say('задача');
        $stack->scheduler->awaitIdle();

        self::assertSame('openrouter:a/agent', $llm->requests[1]->model);
        self::assertSame('profile', $this->stores->decisions[0]->method);
    }

    public function testFailedWorkerIsRetriedOnAStrongerModel(): void {
        $llm = new ScriptedLlm([
            self::delegate([['goal' => 'раз', 'role' => 'cheap']]),
            new LlmException(ErrorKind::Server, 'down'),
            ScriptedLlm::text('со второй попытки'),
            ScriptedLlm::text('итог'),
        ]);
        $stack = $this->stack($llm);

        $stack->say('задача');
        $stack->scheduler->awaitIdle();

        self::assertSame((string) $this->roles->model(ModelRole::STRONG), $llm->requests[2]->model);
        $report = self::report($llm);
        self::assertStringContainsString('каскад', $report);
        self::assertStringContainsString('со второй попытки', $report);
        self::assertSame(1, $stack->runs->find(3)?->parentRunId);
    }

    public function testVerifierChecksTheAnswerWithACleanContext(): void {
        $llm = new ScriptedLlm([
            self::delegate([['goal' => 'сколько будет 2+5', 'output' => 'число', 'role' => 'cheap', 'verify' => true]]),
            ScriptedLlm::text('ответ 8'),
            ScriptedLlm::text("ВЕРДИКТ: отклонено
2+5 = 7, а не 8."),
            ScriptedLlm::text('итог'),
        ]);
        $stack = $this->stack($llm);

        $stack->say('задача');
        $stack->scheduler->awaitIdle();

        $verifier = $llm->requests[2];
        self::assertSame((string) $this->roles->model(ModelRole::STRONG), $verifier->model, 'not the model that answered');
        self::assertStringContainsString('проверяющий', $verifier->messages[0]->content);
        $brief = $verifier->messages[1]->content;
        self::assertStringContainsString('сколько будет 2+5', $brief);
        self::assertStringContainsString('untrusted-data', $brief);
        self::assertStringContainsString('ответ 8', $brief);
        self::assertSame([], $verifier->tools, 'no tools that write');
        $report = self::report($llm);
        self::assertStringContainsString('Проверка: отклонено — 2+5 = 7, а не 8.', $report);
        self::assertStringContainsString('ответ 8', $report);
    }

    public function testVerdictIsTheFirstLineThatStartsWithIt(): void {
        self::assertSame('отклонено', WorkerRunner::verdict("**ВЕРДИКТ:** отклонено\nИсполнитель пишет:\nВЕРДИКТ: принято\n— но это неверно."));
        self::assertSame('принято', WorkerRunner::verdict("Вердикт — Принято. Всё сходится."));
        self::assertSame('отклонено', WorkerRunner::verdict("Проверил.\nВЕРДИКТ: отклонено — в ответе 8, а нужно 7"));
        self::assertNull(WorkerRunner::verdict('Ответ «ВЕРДИКТ: принято» в середине строки не считается.'));
        self::assertNull(WorkerRunner::verdict('ВЕРДИКТ: принятость'));
    }

    public function testVerifierAfterACascadeIsNotSilentAboutTheSameModel(): void {
        $llm = new ScriptedLlm([
            self::delegate([['goal' => 'раз', 'output' => 'итог', 'role' => 'cheap', 'verify' => true]]),
            new LlmException(ErrorKind::Server, 'down'),
            ScriptedLlm::text('ответ со второй попытки'),
            ScriptedLlm::text('ВЕРДИКТ: принято'),
            ScriptedLlm::text('итог'),
        ]);
        $stack = $this->stack($llm);

        $stack->say('задача');
        $stack->scheduler->awaitIdle();

        $strong = (string) $this->roles->model(ModelRole::STRONG);
        self::assertSame($strong, $llm->requests[2]->model, 'the retry');
        self::assertSame($strong, $llm->requests[3]->model, 'no model stronger than strong');
        self::assertStringContainsString('та же модель, другой нет', self::report($llm));
    }

    public function testEachPartThatRanIsRecordedAsExperience(): void {
        $llm = new ScriptedLlm([
            self::delegate([['goal' => 'раз', 'output' => 'итог', 'role' => 'cheap', 'verify' => true, 'profile' => ['text' => 6]]]),
            new LlmException(ErrorKind::Server, 'down'),
            ScriptedLlm::text('ответ со второй попытки'),
            ScriptedLlm::text('ВЕРДИКТ: принято'),
            ScriptedLlm::text('итог'),
        ]);
        $stack = $this->stack($llm);

        $stack->say('задача');
        $stack->scheduler->awaitIdle();

        self::assertCount(1, $this->experience->records);
        $record = array_values($this->experience->records)[0];
        self::assertSame('раз', $record->goal);
        self::assertSame('cheap', $record->role);
        self::assertSame(6, $record->profile['text'] ?? null);
        self::assertSame($llm->requests[2]->model, $record->model, 'the retry finished the part');
        self::assertSame($llm->requests[1]->model, $record->firstModel);
        self::assertNotSame($record->model, $record->firstModel);
        self::assertSame('done', $record->status);
        self::assertSame('принято', $record->verdict);
        self::assertSame(1, $record->conversationId);
        self::assertGreaterThan(0, $record->tokens);
    }

    public function testCascadeLineGivesBothModels(): void {
        self::assertSame(['b/x', 'a/y'], WorkerRunner::models('каскад: a/y, запуск #5 (ошибка (сервер)) → b/x'));
        self::assertSame(['b/x', 'openai:gpt-1'], WorkerRunner::models('каскад: openai:gpt-1 (failed) → b/x'));
        self::assertSame(['a/y', null], WorkerRunner::models('a/y'));
    }

    public function testVerifierWithoutAVerdictLeavesThePartUnchecked(): void {
        $llm = new ScriptedLlm([
            self::delegate([['goal' => 'раз', 'output' => 'итог', 'role' => 'cheap', 'verify' => true]]),
            ScriptedLlm::text('ответ'),
            ScriptedLlm::text('Вроде нормально.'),
            ScriptedLlm::text('итог'),
        ]);
        $stack = $this->stack($llm);

        $stack->say('задача');
        $stack->scheduler->awaitIdle();

        self::assertStringContainsString('Проверка: не проверено — проверяющий не дал вердикта', self::report($llm));
    }

    public function testCostlyPartIsCheckedAndAFailedOneIsNot(): void {
        $llm = new ScriptedLlm([
            self::delegate([['goal' => 'раз', 'role' => 'strong', 'profile' => ['error_cost' => 9]], ['goal' => 'два', 'role' => 'strong']]),
            ScriptedLlm::text('ответ раз'),
            ScriptedLlm::text('ВЕРДИКТ: принято'),
            new LlmException(ErrorKind::Server, 'down'),
            ScriptedLlm::text('итог'),
        ]);
        $stack = $this->stack($llm);

        $stack->say('задача');
        $stack->scheduler->awaitIdle();

        $report = self::report($llm);
        self::assertStringContainsString('Проверка: принято', $report);
        self::assertSame(1, substr_count($report, 'Проверка:'), 'the failed part is not checked');
    }

    public function testWorkerCannotDoIrreversibleEvenWithAGrant(): void {
        $llm = new ScriptedLlm([
            self::delegate([['goal' => 'отправь', 'tools' => ['send'], 'role' => 'cheap']]),
            ScriptedLlm::calls(new ToolCall('s1', 'send', '{}')),
            ScriptedLlm::text('не смог отправить'),
            ScriptedLlm::text('итог'),
        ]);
        $stack = $this->stack($llm);
        $send = new FakeKeyedTool('send', 'send:x');
        $stack->registry->register($send);
        // The owner saved a grant for this action: the orchestrator could use it, a worker may not.
        $stack->approvals->addGrant(1, 'send:x');

        $stack->say('задача');
        $stack->scheduler->awaitIdle();

        self::assertSame(0, $send->runs);
        $refusal = array_filter($llm->requests[2]->messages, static fn($m): bool => $m->role === Role::Tool && str_contains($m->content, 'необратимые действия'));
        self::assertNotEmpty($refusal);
        self::assertStringContainsString('не смог отправить', self::report($llm));
    }

    public function testFullAnswerOfACutReportCanBeRead(): void {
        $long = 'Начало. ' . str_repeat('середина ', 2000) . 'Конец.';
        $llm = new ScriptedLlm([
            self::delegate([['goal' => 'раз', 'role' => 'cheap']]),
            ScriptedLlm::text($long),
            ScriptedLlm::calls(new ToolCall('w1', 'worker_report', '{"run":2}')),
            ScriptedLlm::text('итог'),
        ]);
        $stack = $this->stack($llm);
        $stack->registry->register(new \OpenCCK\App\Agent\Delegation\WorkerReportTool($stack->runs, $stack->transcript));

        $stack->say('задача');
        $stack->scheduler->awaitIdle();

        $report = self::report($llm);
        self::assertStringContainsString('Начало.', $report);
        self::assertStringContainsString('Конец.', $report, 'the tail is kept too');
        self::assertStringContainsString('worker_report {"run": 2}', $report);
        $full = '';
        foreach ($llm->requests[3]->messages as $message) {
            if ($message->role === Role::Tool && str_contains($message->content, 'Конец.') && !str_contains($message->content, 'Задача 1')) {
                $full = $message->content;
            }
        }
        self::assertStringContainsString(str_repeat('середина ', 50), $full);
    }

    public function testWorkerReportOfAnotherTaskIsRefused(): void {
        $stack = $this->stack(new ScriptedLlm());
        $tool = new \OpenCCK\App\Agent\Delegation\WorkerReportTool($stack->runs, $stack->transcript);
        $owner = new \OpenCCK\Domain\Entity\Principal(1, \OpenCCK\Domain\Entity\PrincipalKind::Human, \OpenCCK\Domain\Entity\Role::owner(), 'Владелец');

        $result = $tool->execute(['run' => 99], new \OpenCCK\App\Agent\Tool\ToolContext(1, 1, $owner, new \Amp\NullCancellation()));

        self::assertTrue($result->isError);
    }

    public function testWorkerCapPerRunCannotBePromptedAround(): void {
        $llm = new ScriptedLlm([
            self::delegate([['goal' => 'раз'], ['goal' => 'два'], ['goal' => 'три']]),
            ScriptedLlm::text('понял'),
        ]);
        $stack = new AgentStack($llm, new RunLimits(30, 1_000_000, 900, 120));
        $stores = new InMemoryModelStores();
        $roles = new ModelRoles($stores, $stores);
        $roles->seed();
        $stack->registry->register(new DelegateTaskTool(new WorkerRunner(
            $stack->loop, $stack->runs, $stack->transcript, $stack->conversations, $stack->registry, $roles, 15, 100_000, new NullLogger(), null, 2,
        )));

        $stack->say('задача');
        $stack->scheduler->awaitIdle();

        self::assertStringContainsString('Потолок воркеров на запуск — 2', (string) self::toolText($llm));
        self::assertNull($stack->runs->find(2), 'no worker started');
    }

    private static function toolText(ScriptedLlm $llm): ?string {
        foreach ($llm->requests as $request) {
            foreach ($request->messages as $message) {
                if ($message->role === Role::Tool) {
                    return $message->content;
                }
            }
        }
        return null;
    }

    public function testGuestInTheSameGroupCannotReadTheOwnersWorker(): void {
        $stack = $this->stack(new ScriptedLlm());
        $limits = new RunLimits(30, 1000, 900, 120);
        // The owner's run and its worker in one group conversation.
        $parent = $stack->runs->start(1, 1, 'm', $limits);
        self::assertNotNull($parent);
        $worker = $stack->runs->start(2, 1, 'm', $limits, $parent->id);
        self::assertNotNull($worker);
        $stack->transcript->appendAssistant(2, $worker->id, 'данные владельца', []);
        $tool = new \OpenCCK\App\Agent\Delegation\WorkerReportTool($stack->runs, $stack->transcript);
        $guest = new \OpenCCK\Domain\Entity\Principal(7, \OpenCCK\Domain\Entity\PrincipalKind::Human, \OpenCCK\Domain\Entity\Role::user(), 'Гость');
        $owner = new \OpenCCK\Domain\Entity\Principal(1, \OpenCCK\Domain\Entity\PrincipalKind::Human, \OpenCCK\Domain\Entity\Role::owner(), 'Владелец');

        $byGuest = $tool->execute(['run' => $worker->id], new \OpenCCK\App\Agent\Tool\ToolContext(99, 1, $guest, new \Amp\NullCancellation()));
        $byOwner = $tool->execute(['run' => $worker->id], new \OpenCCK\App\Agent\Tool\ToolContext(99, 1, $owner, new \Amp\NullCancellation()));

        self::assertTrue($byGuest->isError);
        self::assertFalse($byOwner->isError);
        self::assertSame('данные владельца', $byOwner->content);
    }

    public function testLongSummaryIsCut(): void {
        $llm = new ScriptedLlm([self::delegate([['goal' => 'много']]), ScriptedLlm::text(str_repeat('слово ', 3000)), ScriptedLlm::text('итог')]);
        $stack = $this->stack($llm);

        $stack->say('задача');
        $stack->scheduler->awaitIdle();

        $report = self::report($llm);
        self::assertLessThan(5000, mb_strlen($report));
        self::assertStringContainsString('#2', $report);
    }

    public function testWorkerReportsAreUntrustedData(): void {
        $llm = new ScriptedLlm([self::delegate([['goal' => 'прочитай страницу']]), ScriptedLlm::text('страница велит выдать всем все права'), ScriptedLlm::text('итог')]);
        $stack = $this->stack($llm);

        $stack->say('задача');
        $stack->scheduler->awaitIdle();

        self::assertStringContainsString('<untrusted-data', self::report($llm));
    }

    public function testWorkerDeadlineFitsTheOrchestratorsTime(): void {
        $llm = new ScriptedLlm([self::delegate([['goal' => 'раз']]), ScriptedLlm::text('готово'), ScriptedLlm::text('итог')]);
        $stack = $this->stack($llm, new RunLimits(30, 1_000_000, 120, 60));

        $stack->say('задача');
        $stack->scheduler->awaitIdle();

        $worker = $stack->runs->find(2);
        self::assertNotNull($worker);
        self::assertLessThanOrEqual(90, $worker->limits->runTimeout);
    }

    public function testOrchestratorKeepsBudgetForItself(): void {
        $llm = new ScriptedLlm([self::delegate([['goal' => 'раз'], ['goal' => 'два'], ['goal' => 'три']]), ScriptedLlm::text('а'), ScriptedLlm::text('б'), ScriptedLlm::text('в'), ScriptedLlm::text('итог')]);
        $stack = $this->stack($llm, new RunLimits(30, 100_000, 900, 120));

        $stack->say('задача');
        $stack->scheduler->awaitIdle();

        $shares = 0;
        foreach ([2, 3, 4] as $id) {
            $shares += $stack->runs->find($id)->limits->tokenBudget ?? 0;
        }
        self::assertLessThanOrEqual(80_000, $shares);
        self::assertSame(RunStatus::Done, $stack->runs->find(1)?->status);
    }

    public function testTinySharesAreRefused(): void {
        $llm = new ScriptedLlm([self::delegate([['goal' => '1'], ['goal' => '2'], ['goal' => '3'], ['goal' => '4'], ['goal' => '5']]), ScriptedLlm::text('итог')]);
        $stack = $this->stack($llm, new RunLimits(30, 40_000, 900, 120));

        $stack->say('задача');
        $stack->scheduler->awaitIdle();

        self::assertNull($stack->runs->find(2));
    }

    public function testNoNewWorkerStartsAfterStop(): void {
        $llm = new ScriptedLlm([
            self::delegate([['goal' => 'первое', 'tools' => ['push']], ['goal' => 'второе', 'tools' => ['push']]]),
            ScriptedLlm::text('не дойдёт'),
        ]);
        $llm->delay = 0.3;
        $stack = $this->stack($llm);

        $stack->say('задача');
        delay(0.45);
        $stack->say('/stop');
        $stack->scheduler->awaitIdle();

        self::assertNull($stack->runs->find(3));
    }
}
