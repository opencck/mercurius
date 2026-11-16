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
use OpenCCK\App\Agent\Model\ModelRoles;
use OpenCCK\Domain\Agent\DangerClass;
use OpenCCK\Domain\Agent\ModelRole;
use OpenCCK\Domain\Agent\RunLimits;
use OpenCCK\Domain\Agent\RunStatus;
use OpenCCK\Test\Support\AgentStack;
use OpenCCK\Test\Support\FakeTool;
use OpenCCK\Test\Support\InMemoryModelStores;
use OpenCCK\Test\Support\ScriptedLlm;
use Psr\Log\NullLogger;

use function Amp\delay;

/**
 * Delegation: workers are nested runs in their own conversations, on the model of their role, within the
 * orchestrator's budget; the orchestrator gets a short report.
 */
final class DelegateTaskToolTest extends AsyncTestCase {
    private ModelRoles $roles;

    private function stack(ScriptedLlm $llm, RunLimits $limits = new RunLimits(30, 1_000_000, 900, 120)): AgentStack {
        $stores = new InMemoryModelStores();
        $this->roles = new ModelRoles($stores, $stores);
        $this->roles->seed();
        $stack = new AgentStack($llm, $limits);
        $stack->registry->register(new FakeTool('read', delay: 0.2));
        $stack->registry->register(new FakeTool('push', DangerClass::Irreversible));
        $stack->registry->register(new DelegateTaskTool(new WorkerRunner(
            $stack->loop, $stack->runs, $stack->transcript, $stack->conversations, $stack->registry, $this->roles, 15, 100_000, new NullLogger(),
        )));
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

        $stack->say('реши задачу');
        $stack->scheduler->awaitIdle();

        self::assertSame(RunStatus::Done, $stack->runs->find(1)?->status);
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
        self::assertStringContainsString('нельзя', $llm->requests[2]->messages[3]->content);
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
        $llm = new ScriptedLlm([self::delegate([['goal' => 'раз']]), $down, ScriptedLlm::text('итог без воркера')]);
        $stack = $this->stack($llm);

        $stack->say('задача');
        $stack->scheduler->awaitIdle();

        self::assertSame(RunStatus::Done, $stack->runs->find(1)?->status);
        self::assertStringContainsString('failed', self::report($llm));
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
