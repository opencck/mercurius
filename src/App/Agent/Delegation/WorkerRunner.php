<?php

declare(strict_types=1);

namespace OpenCCK\App\Agent\Delegation;

use Amp\Future;
use DomainException;
use Mercurius\Llm\Role;
use OpenCCK\App\Agent\AgentLoop;
use OpenCCK\App\Agent\Model\ModelRoles;
use OpenCCK\App\Agent\SilentObserver;
use OpenCCK\App\Agent\Tool\ToolContext;
use OpenCCK\App\Agent\Tool\ToolRegistry;
use OpenCCK\Domain\Agent\DangerClass;
use OpenCCK\Domain\Agent\ModelRole;
use OpenCCK\Domain\Agent\ParticipantPolicy;
use OpenCCK\Domain\Agent\Run;
use OpenCCK\Domain\Agent\RunLimits;
use OpenCCK\Domain\Agent\StopReason;
use OpenCCK\Domain\Repository\ConversationRepository;
use OpenCCK\Domain\Repository\RunRepository;
use OpenCCK\Domain\Repository\TranscriptRepository;
use Psr\Log\LoggerInterface;
use Throwable;

use function Amp\async;

/**
 * Runs the parts of a task as worker runs: nested under the orchestrator's run, each in its own conversation (it does
 * not see the orchestrator's one, and its steps do not land there), on the model of its role, with tools cut down to
 * the orchestrator's, within a budget reserved from the orchestrator's. A worker cannot ask the owner and cannot
 * delegate further. Read-only parts run side by side, the rest one by one.
 *
 * spec: BRIEF.md#часть-1--синхронное-делегирование
 * ref: references/hermes-agent/tools/delegate_tool.py@e96221fa:L204-L395 (_build_child_agent) — empty history, a short
 *      prompt shared by all children, the task in the first message, tools a subset of the parent's, no clarify; here
 *      the budget is shared and reserved up front (hermes only sums the children's spend afterwards)
 * ref: references/hermes-agent/tools/delegate_tool_toolsets.py@e96221fa:L13-L22 — tools a child never gets
 */
final class WorkerRunner {
    /** Tools a worker never gets: no further delegation, no changes to roles and rights. */
    public const FORBIDDEN = ['delegate_task', 'set_model_role', 'set_participant_policy'];
    /** Workers running side by side at most, when all of them only read. */
    private const PARALLEL = 3;
    /** Below this a worker cannot do anything useful: refuse instead of starting it. */
    private const MIN_TOKENS = 2000;
    /** Seconds a worker may take: inside the delegation tool's own timeout. */
    private const RUN_TIMEOUT = 840;

    public const PROMPT = <<<'TXT'
        Ты — воркер: сфокусированный исполнитель части задачи, которую тебе поручил агент-оркестратор. Беседы с
        владельцем ты не видишь — всё нужное есть в задании. Владельцу ты не пишешь и разрешений не спрашиваешь: если
        действие требует подтверждения, оно не выполнится — так и напиши в итоге.

        Сделай задачу инструментами и закончи итогом: сначала результат, затем коротко — что сделано, какие файлы
        созданы или изменены, что не получилось и почему. Без пересказа процесса: длинный итог вытесняет контекст
        оркестратора. Если нужен формат ответа — соблюди его.
        TXT;

    /**
     * @param positive-int $workerMaxTurns
     * @param positive-int $workerTokenBudget
     */
    public function __construct(
        private readonly AgentLoop $loop,
        private readonly RunRepository $runs,
        private readonly TranscriptRepository $transcript,
        private readonly ConversationRepository $conversations,
        private readonly ToolRegistry $registry,
        private readonly ModelRoles $roles,
        private readonly int $workerMaxTurns,
        private readonly int $workerTokenBudget,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * @param list<WorkerTask> $tasks
     * @return list<WorkerReport>
     * @throws DomainException when the orchestrator's budget cannot cover the workers
     */
    public function run(ToolContext $parent, array $tasks): array {
        $orchestrator = $this->runs->find($parent->runId) ?? throw new DomainException('Запуск-оркестратор не найден.');
        $count = count($tasks);
        $left = $orchestrator->limits->tokenBudget - $orchestrator->tokensUsed - $orchestrator->tokensReserved;
        $reserved = min($left, $count * $this->workerTokenBudget);
        if ($reserved < $count * self::MIN_TOKENS || !$this->runs->reserve($orchestrator->id, $reserved)) {
            throw new DomainException(sprintf('Не хватает бюджета токенов на воркеров: осталось %d, нужно не меньше %d.', max(0, $left), $count * self::MIN_TOKENS));
        }
        $share = intdiv($reserved, $count);
        $spent = 0;
        try {
            $jobs = [];
            foreach ($tasks as $index => $task) {
                $jobs[$index] = fn(): WorkerReport => $this->one($parent, $orchestrator, $index + 1, $task, $share);
            }
            $reports = $this->readOnly($parent, $tasks) ? $this->side($jobs) : array_map(static fn(\Closure $job): WorkerReport => $job(), $jobs);
            foreach ($reports as $report) {
                $spent += $report->tokens;
            }
            return array_values($reports);
        } finally {
            $this->runs->settleAuxiliary($orchestrator->id, $reserved, $spent);
        }
    }

    /**
     * @param array<int, \Closure(): WorkerReport> $jobs
     * @return array<int, WorkerReport>
     */
    private function side(array $jobs): array {
        $reports = [];
        $futures = [];
        foreach ($jobs as $index => $job) {
            $futures[$index] = async($job);
            if (count($futures) === self::PARALLEL) {
                $reports += Future\await($futures);
                $futures = [];
            }
        }
        if ($futures !== []) {
            $reports += Future\await($futures);
        }
        ksort($reports);
        // Every job returns a WorkerReport; the futures lose the type on the way.
        /** @var array<int, WorkerReport> $reports */
        return $reports;
    }

    /** @param list<WorkerTask> $tasks */
    private function readOnly(ToolContext $parent, array $tasks): bool {
        foreach ($tasks as $task) {
            foreach ($this->tools($parent, $task) as $name) {
                if ($this->registry->get($name)?->danger([]) !== DangerClass::Read) {
                    return false;
                }
            }
        }
        return true;
    }

    /** @return list<string> the tools the worker gets: asked for, allowed to the orchestrator, existing, not forbidden */
    private function tools(ToolContext $parent, WorkerTask $task): array {
        $tools = [];
        foreach (array_unique($task->tools) as $name) {
            if (in_array($name, self::FORBIDDEN, true) || $this->registry->get($name) === null) {
                continue;
            }
            if ($parent->policy !== null && !$parent->policy->allowsTool($name)) {
                continue;
            }
            $tools[] = $name;
        }
        return $tools;
    }

    private function one(ToolContext $parent, Run $orchestrator, int $index, WorkerTask $task, int $budget): WorkerReport {
        $role = in_array($task->role, [ModelRole::CHEAP, ModelRole::MAIN, ModelRole::STRONG], true) ? $task->role : ModelRole::MAIN;
        $model = (string) $this->roles->model($role);
        try {
            $conversation = $this->conversations->openConversation($parent->principal->id, 'worker', sprintf('run%d-%s-%d', $orchestrator->id, $parent->callId, $index));
            $this->conversations->setMeta($conversation, self::PROMPT, $model);
            $limits = new RunLimits(
                $this->workerMaxTurns,
                max(1, $budget),
                min(self::RUN_TIMEOUT, $orchestrator->limits->runTimeout),
                $orchestrator->limits->toolTimeout,
            );
            $run = $this->runs->start($conversation, $parent->principal->id, $model, $limits, $orchestrator->id);
            if ($run === null) {
                return new WorkerReport($index, null, 'failed', $model, 0, 0, '', 'не удалось начать запуск воркера');
            }
            $this->transcript->appendUser($conversation, $run->id, self::brief($task));
            $policy = new ParticipantPolicy($this->tools($parent, $task), $limits, $parent->policy?->sandbox, false);
            $final = $this->loop->run($run, $policy, $parent->principal, new SilentObserver(), $parent->cancellation);
        } catch (Throwable $e) {
            $this->logger->error('Worker failed to run', ['parent' => $orchestrator->id, 'task' => $index, 'error' => $e::class]);
            return new WorkerReport($index, $run->id ?? null, 'failed', $model, 0, 0, '', 'внутренняя ошибка');
        }
        return new WorkerReport(
            $index,
            $final->id,
            self::status($final->stopReason),
            $model,
            $final->turns,
            $final->tokensUsed,
            $this->summary($final->id),
            $final->stopReason?->label(),
        );
    }

    private static function brief(WorkerTask $task): string {
        $parts = [];
        if (trim($task->context) !== '') {
            $parts[] = "КОНТЕКСТ:\n" . trim($task->context);
        }
        $parts[] = "ЗАДАЧА:\n" . trim($task->goal);
        if (trim($task->output) !== '') {
            $parts[] = "НУЖЕН ОТВЕТ:\n" . trim($task->output);
        }
        return implode("\n\n", $parts);
    }

    private static function status(?StopReason $reason): string {
        return match (true) {
            $reason === StopReason::Answered => 'done',
            $reason === StopReason::Stopped => 'stopped',
            $reason !== null && $reason->isLimit() => 'limit',
            default => 'failed',
        };
    }

    /** The worker's last answer: what it reports to the orchestrator. */
    private function summary(int $runId): string {
        $messages = $this->transcript->runMessages($runId);
        for ($i = count($messages) - 1; $i >= 0; $i--) {
            if ($messages[$i]->role === Role::Assistant && $messages[$i]->toolCalls === [] && trim($messages[$i]->content) !== '') {
                return trim($messages[$i]->content);
            }
        }
        return '';
    }
}
