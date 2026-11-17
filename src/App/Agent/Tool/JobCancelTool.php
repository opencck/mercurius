<?php

declare(strict_types=1);

namespace OpenCCK\App\Agent\Tool;

use Mercurius\Llm\ToolDefinition;
use OpenCCK\App\Job\JobScheduler;
use OpenCCK\Domain\Agent\DangerClass;
use OpenCCK\Domain\Job\Job;
use OpenCCK\Domain\Job\Schedule;
use OpenCCK\Domain\Repository\JobRepository;

/**
 * The jobs of the caller and cancelling one of them.
 *
 * spec: BRIEF.md#планировщик-и-проекты
 */
final class JobCancelTool implements Tool {
    public const NAME = 'job_cancel';

    public function __construct(
        private readonly JobRepository $jobs,
        private readonly JobScheduler $scheduler,
    ) {}

    public function definition(): ToolDefinition {
        return new ToolDefinition(self::NAME, 'Задания по расписанию: без id — список своих заданий; с id — отменить задание.', [
            'type' => 'object',
            'properties' => ['id' => ['type' => 'integer', 'minimum' => 1]],
            'additionalProperties' => false,
        ]);
    }

    public function danger(array $args): DangerClass {
        return Args::int($args, 'id') === null ? DangerClass::Read : DangerClass::Write;
    }

    public function untrusted(): bool {
        return false;
    }

    public function timeout(): int {
        return 10;
    }

    public function execute(array $args, ToolContext $context): ToolResult {
        $id = Args::int($args, 'id');
        if ($id === null) {
            $list = $this->jobs->list($context->principal->isOwner() ? null : $context->principal->id);
            return ToolResult::ok($list === [] ? 'Заданий нет.' : implode("\n", array_map(fn(Job $j): string => self::line($j, $this->scheduler), $list)));
        }
        $job = $this->jobs->find($id);
        if ($job === null || (!$context->principal->isOwner() && $job->principalId !== $context->principal->id)) {
            return ToolResult::error(sprintf('Задания #%d нет.', $id));
        }
        $this->jobs->setStatus($id, Job::DONE);
        return ToolResult::ok(sprintf('Задание #%d «%s» отменено.', $id, $job->title));
    }

    public static function line(Job $job, JobScheduler $scheduler): string {
        try {
            $when = Schedule::parse($job->schedule)->describe();
        } catch (\InvalidArgumentException) {
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
