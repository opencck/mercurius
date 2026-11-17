<?php

declare(strict_types=1);

namespace OpenCCK\App\Agent\Tool;

use DateTimeImmutable;
use InvalidArgumentException;
use Mercurius\Llm\ToolDefinition;
use OpenCCK\App\Job\JobScheduler;
use OpenCCK\Domain\Agent\DangerClass;
use OpenCCK\Domain\Job\Schedule;
use OpenCCK\Domain\Repository\ConversationRepository;
use OpenCCK\Domain\Repository\JobRepository;
use OpenCCK\Memory\Domain\ContentScreen;

/**
 * The agent sets itself a reminder or a check: a job by a schedule, its report comes to this chat.
 *
 * spec: BRIEF.md#планировщик-и-проекты — the agent sets reminders and checks itself; the text of a job is checked
 *       for injections
 */
final class JobCreateTool implements Tool {
    public const NAME = 'job_create';
    private const PROMPT_MAX = 4000;

    public function __construct(
        private readonly JobRepository $jobs,
        private readonly ConversationRepository $conversations,
        private readonly JobScheduler $scheduler,
        private readonly ContentScreen $screen,
    ) {}

    public function definition(): ToolDefinition {
        return new ToolDefinition(self::NAME, 'Ставит задание по расписанию: напоминание, проверку, сводку. Оно выполнится без владельца в новой '
            . 'беседе, итог придёт в этот чат. schedule: once:<время ISO 8601> — однажды; every:<минут, от 5> — периодически; '
            . 'daily:<ЧЧ:ММ> — каждый день по часовому поясу владельца. prompt — что сделать, полностью, без ссылок на «этот разговор».', [
            'type' => 'object',
            'properties' => [
                'title' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 100],
                'prompt' => ['type' => 'string', 'minLength' => 1, 'maxLength' => self::PROMPT_MAX],
                'schedule' => ['type' => 'string', 'minLength' => 7, 'maxLength' => 60],
            ],
            'required' => ['title', 'prompt', 'schedule'],
            'additionalProperties' => false,
        ]);
    }

    public function danger(array $args): DangerClass {
        return DangerClass::Write;
    }

    public function untrusted(): bool {
        return false;
    }

    public function timeout(): int {
        return 10;
    }

    public function execute(array $args, ToolContext $context): ToolResult {
        $title = trim(Args::string($args, 'title') ?? '');
        $prompt = trim(Args::string($args, 'prompt') ?? '');
        $problem = $this->screen->problem($prompt, self::PROMPT_MAX) ?? $this->screen->problem($title, 100);
        if ($problem !== null) {
            return ToolResult::error('Текст задания не принят: ' . $problem);
        }
        try {
            $schedule = Schedule::parse(Args::string($args, 'schedule') ?? '');
        } catch (InvalidArgumentException $e) {
            return ToolResult::error($e->getMessage());
        }
        $first = $schedule->next(new DateTimeImmutable(), $this->scheduler->zone(), true);
        if ($first === null) {
            return ToolResult::error('Это время уже прошло.');
        }
        $meta = $this->conversations->meta($context->conversationId);
        if ($meta === null) {
            return ToolResult::error('Беседа не найдена.');
        }
        $job = $this->jobs->create($context->principal->id, $meta->channel, $meta->chatId, $title, $prompt, (string) $schedule, $first);
        return ToolResult::ok(sprintf(
            'Задание #%d «%s» поставлено: %s, первый запуск %s.',
            $job->id,
            $job->title,
            $schedule->describe(),
            $first->setTimezone($this->scheduler->zone())->format('Y-m-d H:i T'),
        ));
    }
}
