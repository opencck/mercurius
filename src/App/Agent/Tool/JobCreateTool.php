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

use function Mercurius\I18n\t;

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
        /** the runs, to see whether the one setting the job up had read outside data; null — not marked */
        private readonly ?\OpenCCK\Domain\Repository\RunRepository $runs = null,
    ) {}

    public function definition(): ToolDefinition {
        return new ToolDefinition(self::NAME, t('tool.job_create.stavit_zadanie_po_raspisaniyu_napominani')
            . t('tool.job_create.besede_itog_pridet_v_etot')
            . t('tool.job_create.daily_chch_mm_kazhdyy_den')
            . t('tool.job_create.komandoy_hook_prompt_chto_sdelat'), [
            'type' => 'object',
            'properties' => [
                'title' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 100],
                'prompt' => ['type' => 'string', 'minLength' => 1, 'maxLength' => self::PROMPT_MAX],
                'schedule' => ['type' => 'string', 'minLength' => 5, 'maxLength' => 60],
                'watch' => ['type' => 'string', 'pattern' => '^https?://', 'maxLength' => 2048, 'description' => t('tool.job_create.watch')],
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
            return ToolResult::error(t('tool.job_create.tekst_zadaniya_ne_prinyat') . $problem);
        }
        try {
            $schedule = Schedule::parse(Args::string($args, 'schedule') ?? '');
        } catch (InvalidArgumentException $e) {
            return ToolResult::error($e->getMessage());
        }
        $first = $schedule->next(new DateTimeImmutable(), $this->scheduler->zone($context->principal->id), true);
        $byEvent = (string) $schedule === Schedule::EVENT;
        if ($first === null && !$byEvent) {
            return ToolResult::error(t('tool.job_create.eto_vremya_uzhe_proshlo'));
        }
        $meta = $this->conversations->meta($context->conversationId);
        if ($meta === null) {
            return ToolResult::error(t('tool.job_create.beseda_ne_naydena'));
        }
        $watch = trim(Args::string($args, 'watch') ?? '');
        if ($watch !== '' && ($byEvent || preg_match('~^https?://[^\s/]+~i', $watch) !== 1)) {
            return ToolResult::error(t('tool.job_create.watch_bad'));
        }
        // spec: BRIEF.md#решения-по-доработкам-2026-11-18 — a job set up after outside data carries the mark to its runs
        $origin = $this->runs?->find($context->runId)?->outside;
        $job = $this->jobs->create($context->principal->id, $meta->channel, $meta->chatId, $title, $prompt, (string) $schedule, $first, $origin);
        if ($watch !== '') {
            // spec: BRIEF.md#планировщик-и-проекты — the job watches the address: the model only when it changed
            $this->jobs->setWatch($job->id, $watch);
        }
        if ($first === null) {
            return ToolResult::ok(sprintf(t('tool.job_create.zadanie_postavleno_po_sobytiyu_vladelets'), $job->id, $job->title, $job->id));
        }
        return ToolResult::ok(sprintf(
            t('tool.job_create.zadanie_postavleno_pervyy_zapusk'),
            $job->id,
            $job->title,
            $schedule->describe(),
            $first->setTimezone($this->scheduler->zone($context->principal->id))->format('Y-m-d H:i T'),
        ));
    }
}
