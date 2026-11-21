<?php

declare(strict_types=1);

namespace OpenCCK\App\Agent\Delegation;

use DomainException;
use Mercurius\Llm\ToolDefinition;
use OpenCCK\App\Agent\Model\TaskProfile;
use OpenCCK\App\Agent\Tool\LongRunning;
use OpenCCK\App\Agent\Tool\Tool;
use OpenCCK\App\Agent\Tool\ToolContext;
use OpenCCK\App\Agent\Tool\ToolResult;
use OpenCCK\Domain\Agent\DangerClass;

use function Mercurius\I18n\t;

/**
 * Hands parts of a task to workers and returns their short reports. A write: it spends the run's budget.
 *
 * spec: brief/orchestration.md#часть-1--синхронное-делегирование
 * ref: references/hermes-agent/tools/delegate_tool.py@e96221fa:L843-L922 — tasks with goal and context ("each child
 *      sees only its own context"); here also the expected output, the tools and the model role
 * ref: references/hermes-agent/tools/delegate_tool_results.py@e96221fa:L254-L309 (_apply_summary_budget) — a cap on
 *      each summary so the reports do not crowd out the orchestrator's context
 */
final class DelegateTaskTool implements Tool, LongRunning, \OpenCCK\App\Agent\Tool\ReadsOutside {
    public const NAME = 'delegate_task';
    private const MAX_TASKS = 5;

    /** @var (\Closure(int): bool)|null a conversation where a late report has nobody to reach: workers are awaited there */
    private ?\Closure $foreground = null;

    public function __construct(
        private readonly WorkerRunner $workers,
        private readonly ?BackgroundDelegations $background = null,
    ) {}

    /**
     * spec: brief/scheduler.md#как-сделано-задания-и-доставка-итогов — a job run ends with its report; a background
     *       report would come to its conversation after the end, where nobody reads it, so the run waits for the workers
     *
     * @param \Closure(int): bool $foreground by the id of the conversation
     */
    public function foregroundIn(\Closure $foreground): void {
        $this->foreground = $foreground;
    }

    public function definition(): ToolDefinition {
        return new ToolDefinition(self::NAME, t('delegation.delegate_task_tool.poruchaet_chasti_zadachi_vorkeram_otdeln')
            . t('delegation.delegate_task_tool.deli_tolko_kogda_chasti_nezavisimy')
            . t('delegation.delegate_task_tool.rashody_vorker_ne_vidit_besedy')
            . t('delegation.delegate_task_tool.parallelno_role_cheap_prostaya_massovaya')
            . t('delegation.delegate_task_tool.kazhdoy_zadache_svedi_ih_sam')
            . t('delegation.delegate_task_tool.ne_nuzhen_v_etom_hode')
            . t('delegation.delegate_task_tool.stages'), [
            'type' => 'object',
            'properties' => [
                'tasks' => [
                    'type' => 'array',
                    'minItems' => 1,
                    'maxItems' => self::MAX_TASKS,
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'goal' => ['type' => 'string', 'minLength' => 1, 'description' => t('delegation.delegate_task_tool.chto_sdelat_konkretno_i_samodostatochno')],
                            'context' => ['type' => 'string', 'description' => t('delegation.delegate_task_tool.fon_kotoryy_nuzhen_vorkeru')],
                            'output' => ['type' => 'string', 'description' => t('delegation.delegate_task_tool.kakoy_nuzhen_otvet')],
                            'tools' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => t('delegation.delegate_task_tool.instrumenty_vorkera')],
                            'role' => ['type' => 'string', 'enum' => ['cheap', 'main', 'strong']],
                            'profile' => [
                                'type' => 'object',
                                'description' => t('delegation.delegate_task_tool.vmesto_role_otsenki_1_10'),
                                'properties' => array_fill_keys(TaskProfile::AXES, ['type' => 'integer', 'minimum' => 1, 'maximum' => 10]),
                                'additionalProperties' => false,
                            ],
                            'verify' => ['type' => 'boolean', 'description' => t('delegation.delegate_task_tool.proverit_otvet_otdelnym_proveryayuschim')],
                            // spec: brief/orchestration.md#права-воркера-задаёт-оркестратор
                            'mode' => ['type' => 'string', 'enum' => WorkerTask::MODES, 'description' => t('delegation.delegate_task_tool.mode')],
                            'memory' => ['type' => 'string', 'enum' => WorkerTask::MEMORY, 'description' => t('delegation.delegate_task_tool.memory')],
                            'context_of' => ['type' => 'array', 'items' => ['type' => 'string', 'enum' => WorkerTask::CONTEXT_OF], 'description' => t('delegation.delegate_task_tool.context_of')],
                            // spec: brief/orchestration.md#схема-ответа-воркера
                            'output_schema' => ['type' => 'object', 'description' => t('delegation.delegate_task_tool.output_schema')],
                            // spec: brief/orchestration.md#этапы-и-план
                            'todo' => ['type' => 'string', 'description' => t('delegation.delegate_task_tool.todo')],
                            'after' => ['type' => 'array', 'items' => ['type' => 'integer', 'minimum' => 1], 'description' => t('delegation.delegate_task_tool.after')],
                        ],
                        'required' => ['goal'],
                        'additionalProperties' => false,
                    ],
                ],
                'background' => ['type' => 'boolean', 'description' => t('delegation.delegate_task_tool.vypolnit_v_fone_otvet_pridet')],
                // spec: brief/orchestration.md#этапы-и-план — the orchestrator sees, steers and stops its background parts, as in hermes
                'action' => ['type' => 'string', 'enum' => ['run', 'list', 'steer', 'stop'], 'description' => t('delegation.delegate_task_tool.action')],
                'delegation' => ['type' => 'integer', 'description' => t('delegation.delegate_task_tool.delegation')],
                'part' => ['type' => 'integer', 'minimum' => 1, 'description' => t('delegation.delegate_task_tool.part')],
                'text' => ['type' => 'string', 'description' => t('delegation.delegate_task_tool.text')],
            ],
            'additionalProperties' => false,
        ]);
    }

    public function danger(array $args): DangerClass {
        return ($args['action'] ?? 'run') === 'list' ? DangerClass::Read : DangerClass::Write;
    }

    /** Workers read pages and files: their reports carry what they read, so they are data, not instructions. */
    /** spec: brief/agent-loop.md#подтверждения-и-запреты — a worker may have read pages: its report carries their text */
    public function readsOutside(array $args): bool {
        return true;
    }

    public function untrusted(): bool {
        return true;
    }

    public function timeout(): int {
        return 900;
    }

    public function execute(array $args, ToolContext $context): ToolResult {
        $action = isset($args['action']) && is_string($args['action']) ? $args['action'] : 'run';
        if ($action !== 'run') {
            return $this->manage($action, $args, $context);
        }
        $tasks = [];
        $raw = isset($args['tasks']) && is_array($args['tasks']) ? $args['tasks'] : [];
        if ($raw !== []) {
            foreach (array_keys($raw) as $key) {
                /** @var mixed $item */
                $item = $raw[$key];
                if (!is_array($item)) {
                    continue;
                }
                [$mode, $memory, $of] = WorkerTask::rights($item);
                $schema = null;
                if (array_key_exists('output_schema', $item)) {
                    $schema = OutputContract::coerce($item['output_schema']);
                    if (is_string($schema)) {
                        return ToolResult::error('output_schema: ' . $schema);
                    }
                }
                // a verifier checks the answer against what was asked for: it needs to know what that was
                if (($item['verify'] ?? false) === true && trim(self::field($item, 'output') ?? '') === '' && $schema === null) {
                    return ToolResult::error(t('delegation.delegate_task_tool.verify_needs_output'));
                }
                $position = count($tasks) + 1;
                $after = WorkerTask::positions($item['after'] ?? null);
                foreach ($after as $before) {
                    if ($before >= $position) {
                        return ToolResult::error(sprintf(t('delegation.delegate_task_tool.after_bad'), $position));
                    }
                }
                $todo = trim(self::field($item, 'todo') ?? '');
                $tasks[] = new WorkerTask(
                    self::field($item, 'goal') ?? '',
                    self::field($item, 'context') ?? '',
                    self::field($item, 'output') ?? '',
                    self::names($item),
                    self::field($item, 'role') ?? 'main',
                    isset($item['profile']) && is_array($item['profile']) ? TaskProfile::fromArray($item['profile']) : null,
                    ($item['verify'] ?? false) === true,
                    $mode,
                    $memory,
                    $of,
                    $schema,
                    $todo === '' ? null : $todo,
                    $after,
                );
            }
        }
        if ($tasks === [] || count($tasks) > self::MAX_TASKS) {
            return ToolResult::error(sprintf(t('delegation.delegate_task_tool.nuzhno_ot_1_do_zadach'), self::MAX_TASKS));
        }
        try {
            if (($args['background'] ?? false) === true && $this->background !== null && !($this->foreground !== null && ($this->foreground)($context->conversationId))) {
                $delegation = $this->background->spawn($context, $tasks);
                return ToolResult::ok(sprintf(
                    t('delegation.delegate_task_tool.fonovoe_delegirovanie_nachato_zadach_otc')
                    . t('delegation.delegate_task_tool.zadachi_zakonchatsya_seychas_ego_ne'),
                    $delegation->id,
                    count($tasks),
                ));
            }
            $reports = $this->workers->run($context, $tasks);
        } catch (DomainException $e) {
            return ToolResult::error($e->getMessage());
        }
        return ToolResult::ok(WorkerReports::text($reports, $this->workers->reportRoom($context)));
    }

    /**
     * spec: brief/orchestration.md#этапы-и-план — list, steer or stop the background parts of this conversation
     * ref: references/hermes-agent/tools/delegate_tool.py@e96221fa:L621-L628, L903-L919 — list/steer/stop of running
     *      children: steer reaches the child's next step without stopping it, stop ends one child; here a part is named
     *      by its delegation and position, and continue of a finished child is not taken
     *
     * @param array<array-key, mixed> $args
     */
    private function manage(string $action, array $args, ToolContext $context): ToolResult {
        if ($this->background === null) {
            return ToolResult::error(t('delegation.delegate_task_tool.no_background'));
        }
        if ($action === 'list') {
            $lines = [];
            foreach ($this->background->parts($context->conversationId) as $part) {
                $lines[] = sprintf(
                    t('delegation.delegate_task_tool.list_line'),
                    $part['delegation'],
                    $part['part'],
                    t($part['running'] ? 'delegation.delegate_task_tool.part_running' : 'delegation.delegate_task_tool.part_waiting'),
                    mb_substr($part['goal'], 0, 200),
                );
            }
            return ToolResult::ok($lines === [] ? t('delegation.delegate_task_tool.no_background') : implode("\n", $lines));
        }
        $delegation = isset($args['delegation']) && is_int($args['delegation']) ? $args['delegation'] : 0;
        $part = isset($args['part']) && is_int($args['part']) ? $args['part'] : 0;
        if ($delegation < 1 || $part < 1) {
            return ToolResult::error(t('delegation.delegate_task_tool.needs_part'));
        }
        if ($action === 'stop') {
            return $this->background->stopPart($context->conversationId, $delegation, $part)
                ? ToolResult::ok(sprintf(t('delegation.delegate_task_tool.stopped_part'), $part, $delegation))
                : ToolResult::error(sprintf(t('delegation.delegate_task_tool.not_running'), $part, $delegation));
        }
        $text = trim(self::field($args, 'text') ?? '');
        if ($action !== 'steer' || $text === '') {
            return ToolResult::error(t('delegation.delegate_task_tool.needs_text'));
        }
        return $this->background->steerPart($context->conversationId, $delegation, $part, mb_substr($text, 0, 2000))
            ? ToolResult::ok(sprintf(t('delegation.delegate_task_tool.steered_part'), $part, $delegation))
            : ToolResult::error(sprintf(t('delegation.delegate_task_tool.not_running'), $part, $delegation));
    }

    /** @param array<array-key, mixed> $item */
    private static function field(array $item, string $key): ?string {
        return isset($item[$key]) && is_string($item[$key]) ? $item[$key] : null;
    }

    /**
     * @param array<array-key, mixed> $item
     * @return list<string>
     */
    private static function names(array $item): array {
        $tools = isset($item['tools']) && is_array($item['tools']) ? $item['tools'] : [];
        $names = [];
        foreach (array_keys($tools) as $i) {
            if (isset($tools[$i]) && is_string($tools[$i])) {
                $names[] = $tools[$i];
            }
        }
        return $names;
    }
}
