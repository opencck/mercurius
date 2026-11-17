<?php

declare(strict_types=1);

namespace OpenCCK\App\Agent\Tool;

use DateTimeImmutable;
use InvalidArgumentException;
use Mercurius\Llm\ToolDefinition;
use OpenCCK\App\Job\JobScheduler;
use OpenCCK\Domain\Agent\DangerClass;
use OpenCCK\Domain\Job\Job;
use OpenCCK\Domain\Job\Schedule;
use OpenCCK\Domain\Repository\JobRepository;

/**
 * The jobs of the caller: the list, cancelling, a pause, resuming and a run now.
 *
 * spec: BRIEF.md#планировщик-и-проекты
 * ref: references/hermes-agent/cron/jobs.py@e96221fa:L2141-L2217 (pause_job, resume_job, trigger_job) — pause, resume and
 *      run now; here resuming counts the next run from now, so a slot missed while paused does not run at once
 */
final class JobManageTool implements Tool {
    public const NAME = 'job_manage';
    public const ACTIONS = ['list', 'cancel', 'pause', 'resume', 'run_now'];

    public function __construct(
        private readonly JobRepository $jobs,
        private readonly JobScheduler $scheduler,
    ) {}

    public function definition(): ToolDefinition {
        return new ToolDefinition(self::NAME, 'Задания по расписанию. action: list — список своих заданий (по умолчанию); cancel — отменить; '
            . 'pause — приостановить; resume — возобновить; run_now — запустить сейчас, не меняя расписания. Для всех, кроме list, нужен id.', [
            'type' => 'object',
            'properties' => [
                'action' => ['type' => 'string', 'enum' => self::ACTIONS],
                'id' => ['type' => 'integer', 'minimum' => 1],
            ],
            'additionalProperties' => false,
        ]);
    }

    public function danger(array $args): DangerClass {
        return in_array(Args::string($args, 'action') ?? 'list', ['list'], true) && Args::int($args, 'id') === null ? DangerClass::Read : DangerClass::Write;
    }

    public function untrusted(): bool {
        return false;
    }

    public function timeout(): int {
        return 10;
    }

    public function execute(array $args, ToolContext $context): ToolResult {
        $id = Args::int($args, 'id');
        $action = Args::string($args, 'action') ?? ($id === null ? 'list' : 'cancel');
        if ($action === 'list') {
            $list = $this->jobs->list($context->principal->isOwner() ? null : $context->principal->id);
            return ToolResult::ok($list === [] ? 'Заданий нет.' : implode("\n", array_map(fn(Job $j): string => self::line($j, $this->scheduler), $list)));
        }
        $job = $id === null ? null : $this->jobs->find($id);
        if ($job === null || $job->status === Job::DONE || (!$context->principal->isOwner() && $job->principalId !== $context->principal->id)) {
            return ToolResult::error(sprintf('Задания #%d нет.', (int) $id));
        }
        return match ($action) {
            'cancel' => $this->set($job, Job::DONE, 'отменено'),
            'pause' => $this->set($job, Job::PAUSED, 'приостановлено'),
            'resume' => $this->resume($job),
            'run_now' => $this->runNow($job),
            default => ToolResult::error('Неизвестное действие: ' . $action),
        };
    }

    private function set(Job $job, string $status, string $what): ToolResult {
        $this->jobs->setStatus($job->id, $status);
        return ToolResult::ok(sprintf('Задание #%d «%s» %s.', $job->id, $job->title, $what));
    }

    private function resume(Job $job): ToolResult {
        if ($job->status === Job::ACTIVE) {
            return ToolResult::ok(sprintf('Задание #%d «%s» и так идёт.', $job->id, $job->title));
        }
        if (!$job->byEvent()) {
            try {
                $next = Schedule::parse($job->schedule)->next(new DateTimeImmutable(), $this->scheduler->zone());
            } catch (InvalidArgumentException) {
                $next = null;
            }
            if ($next === null) {
                return ToolResult::error(sprintf('У задания #%d нет следующего запуска: разовое время прошло.', $job->id));
            }
            $this->jobs->setNext($job->id, $next);
        }
        return $this->set($job, Job::ACTIVE, 'возобновлено');
    }

    private function runNow(Job $job): ToolResult {
        if ($job->byEvent()) {
            return ToolResult::error('Задание по событию запускается webhook, а не по команде.');
        }
        if ($job->status !== Job::ACTIVE) {
            return ToolResult::error(sprintf('Задание #%d на паузе: сначала resume.', $job->id));
        }
        $this->jobs->setNext($job->id, new DateTimeImmutable());
        return ToolResult::ok(sprintf('Задание #%d «%s» запустится в течение минуты; дальше — по расписанию.', $job->id, $job->title));
    }

    public static function line(Job $job, JobScheduler $scheduler): string {
        try {
            $when = Schedule::parse($job->schedule)->describe();
        } catch (InvalidArgumentException) {
            $when = $job->schedule;
        }
        return sprintf(
            '#%d «%s» — %s, %s; следующий: %s; запусков %d',
            $job->id,
            $job->title,
            $when,
            $job->status === Job::ACTIVE ? 'включено' : 'на паузе',
            $job->nextRunAt === null ? '—' : $job->nextRunAt->setTimezone($scheduler->zone())->format('Y-m-d H:i'),
            $job->runs,
        );
    }
}
