<?php

declare(strict_types=1);

namespace OpenCCK\App\Agent\Tool\Self;

use Mercurius\Llm\ToolDefinition;
use OpenCCK\App\Agent\Tool\Args;
use OpenCCK\App\Agent\Tool\Tool;
use OpenCCK\App\Agent\Tool\ToolContext;
use OpenCCK\App\Agent\Tool\ToolResult;
use OpenCCK\App\Service\TraceReports;
use OpenCCK\Domain\Agent\DangerClass;
use OpenCCK\Domain\Repository\RunRepository;

use function Mercurius\I18n\t;

/**
 * The agent's own spend: tokens of this run so far, a run by its number, recent runs, spend by day and model. The
 * owner asked how many tokens an answer took and the agent could only guess.
 *
 * spec: BRIEF.md#трассировка-и-метрики — the agent sees its own spend; a participant sees only the current run
 */
final class SpendingTool implements Tool {
    public const NAME = 'spending';

    public function __construct(
        private readonly TraceReports $reports,
        private readonly RunRepository $runs,
        /** finds the run that wrote a message of the chat */
        private readonly ?\OpenCCK\Domain\Repository\RunMessageRepository $messages = null,
        private readonly ?\OpenCCK\Domain\Repository\ConversationRepository $conversations = null,
        private readonly ?\OpenCCK\Domain\Repository\PrincipalRepository $principals = null,
        /** the owner's zone: periods are their days */
        private readonly string $timezone = 'UTC',
    ) {}

    public function definition(): ToolDefinition {
        return new ToolDefinition(self::NAME, t('tool.spending.rashod_tokenov_i_deneg_action')
            . t('tool.spending.kotoryy_napisal_soobschenie_message_id')
            . t('tool.spending.recent_poslednie_zapuski_days_rashod')
            . t('tool.spending.breakdown_about')
            . t('tool.spending.runs_about')
            . t('tool.spending.chisla_beri_tolko_otsyuda_i'), [
            'type' => 'object',
            'properties' => [
                'action' => ['type' => 'string', 'enum' => ['run', 'recent', 'runs', 'days', 'breakdown']],
                'run_ids' => ['type' => 'array', 'items' => ['type' => 'integer', 'minimum' => 1], 'maxItems' => 50],
                'by' => ['type' => 'string', 'enum' => \OpenCCK\Domain\Agent\SpendQuery::BY, 'description' => t('tool.spending.by')],
                'from' => ['type' => 'string', 'description' => t('tool.spending.from')],
                'to' => ['type' => 'string', 'description' => t('tool.spending.to')],
                'here' => ['type' => 'boolean', 'description' => t('tool.spending.here')],
                'participant' => ['type' => 'string', 'maxLength' => 100, 'description' => t('tool.spending.participant')],
                'run_id' => ['type' => 'integer', 'minimum' => 1],
                'message_id' => ['type' => 'integer', 'minimum' => 1],
                'days' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 366],
            ],
            'additionalProperties' => false,
        ]);
    }

    public function danger(array $args): DangerClass {
        return DangerClass::Read;
    }

    public function untrusted(): bool {
        return false;
    }

    public function timeout(): int {
        return 15;
    }

    /**
     * Spend split by topic, chat, project, participant, model or day, for a period of the owner's days, here or
     * everywhere. A participant gets only their own figures, whoever they name.
     *
     * spec: BRIEF.md#трассировка-и-метрики — spend by topics, projects, participants and periods
     *
     * @param array<string, mixed> $args
     */
    private function breakdown(array $args, ToolContext $context): ToolResult {
        $by = Args::string($args, 'by') ?? 'topic';
        if (!in_array($by, \OpenCCK\Domain\Agent\SpendQuery::BY, true)) {
            return ToolResult::error(t('tool.spending.bad_by'));
        }
        try {
            $zone = new \DateTimeZone($this->timezone === '' ? 'UTC' : $this->timezone);
        } catch (\Exception) {
            $zone = new \DateTimeZone('UTC');
        }
        $today = new \DateTimeImmutable('today', $zone);
        $from = self::day(Args::string($args, 'from'), $zone);
        $to = self::day(Args::string($args, 'to'), $zone);
        if ($from === false || $to === false) {
            return ToolResult::error(t('tool.spending.bad_date'));
        }
        $to = ($to ?? $today)->modify('+1 day');
        $from ??= $to->modify(sprintf('-%d days', max(1, min(366, Args::int($args, 'days') ?? 30))));
        if ($from >= $to) {
            return ToolResult::error(t('tool.spending.bad_date'));
        }
        $scope = [];
        $principal = null;
        if (!$context->principal->isOwner()) {
            $principal = $context->principal->id;
            $scope[] = $context->principal->displayName ?? ('#' . $context->principal->id);
        } elseif (($name = trim(Args::string($args, 'participant') ?? '')) !== '') {
            $found = $this->person($name);
            if ($found === null) {
                return ToolResult::error(sprintf(t('tool.spending.no_participant'), $name));
            }
            $principal = $found->id;
            $scope[] = $found->displayName ?? ('#' . $found->id);
        }
        $place = null;
        if (($args['here'] ?? false) === true) {
            $meta = $this->conversations?->meta($context->conversationId);
            if ($meta === null) {
                return ToolResult::error(t('tool.spending.no_place'));
            }
            $place = [$meta->channel, $meta->chatId, $meta->threadId];
            $scope[] = $meta->threadId === '' ? t('tool.spending.this_chat') : t('tool.spending.this_topic');
        }
        $query = new \OpenCCK\Domain\Agent\SpendQuery($by, $from, $to, $principal, $place);
        return ToolResult::ok($this->reports->breakdown($query, implode(', ', $scope)));
    }

    /** @return \DateTimeImmutable|null|false the start of that day; null — not given; false — not a date */
    private static function day(?string $text, \DateTimeZone $zone): \DateTimeImmutable|null|false {
        if ($text === null || trim($text) === '') {
            return null;
        }
        $day = \DateTimeImmutable::createFromFormat('!Y-m-d', trim($text), $zone);
        return $day === false || $day->format('Y-m-d') !== trim($text) ? false : $day;
    }

    private function person(string $name): ?\OpenCCK\Domain\Entity\Principal {
        $key = mb_strtolower(ltrim($name, '#@'));
        $people = $this->principals?->all() ?? [];
        foreach ($people as $p) {
            if ((ctype_digit($key) && $p->id === (int) $key) || mb_strtolower($p->displayName ?? '') === $key) {
                return $p;
            }
        }
        $found = array_values(array_filter($people, static fn(\OpenCCK\Domain\Entity\Principal $p): bool => $key !== '' && str_contains(mb_strtolower($p->displayName ?? ''), $key)));
        return count($found) === 1 ? $found[0] : null;
    }
    public function execute(array $args, ToolContext $context): ToolResult {
        $action = Args::string($args, 'action') ?? 'run';
        if ($action === 'breakdown') {
            return $this->breakdown($args, $context);
        }
        $owner = $context->principal->isOwner();
        if ($action !== 'run' && !$owner) {
            return ToolResult::error(t('tool.spending.obschiy_rashod_vidit_tolko_vladelets'));
        }
        if ($action === 'runs') {
            /** @var mixed $raw */
            $raw = $args['run_ids'] ?? null;
            $ids = is_array($raw) ? array_values(array_filter($raw, static fn(mixed $id): bool => is_int($id) && $id > 0)) : [];
            /** @var list<int> $ids */
            return $ids === [] ? ToolResult::error(t('tool.spending.runs_need_ids')) : ToolResult::ok($this->reports->runsTotal($ids));
        }
        if ($action === 'recent') {
            return ToolResult::ok($this->reports->runs(10));
        }
        if ($action === 'days') {
            return ToolResult::ok($this->reports->usage(Args::int($args, 'days') ?? 7));
        }
        $runId = Args::int($args, 'run_id') ?? $context->runId;
        $messageId = Args::int($args, 'message_id');
        $prefix = '';
        if ($messageId !== null) {
            $meta = $this->conversations?->meta($context->conversationId);
            $found = $meta === null ? null : $this->messages?->runOf($meta->channel, $meta->chatId, (string) $messageId);
            if ($found === null) {
                return ToolResult::error(sprintf(t('tool.spending.neizvestno_kakoy_zapusk_napisal_soobsche'), $messageId));
            }
            $runId = $found;
            $prefix = sprintf(t('tool.spending.soobschenie_napisal_zapusk'), $messageId, $found);
        }
        $run = $this->runs->find($runId);
        if ($run === null || (!$owner && $run->principalId !== $context->principal->id)) {
            return ToolResult::error(sprintf(t('tool.spending.zapuska_net'), $runId));
        }
        $summary = $prefix . ($runId === $context->runId
            ? sprintf(t('tool.spending.zapusk_idet_seychas_potracheno_tokenov'), $run->id, $run->tokensUsed, $run->turns)
            : sprintf(t('tool.spending.zapusk_itogo_tokenov_vyzovov_modeli'), $run->id, $run->tokensUsed, $run->turns));
        if (!$owner) {
            return ToolResult::ok($summary);
        }
        return ToolResult::ok($summary . "\n\n" . ($this->reports->trace($runId) ?? t('tool.spending.trassy_etogo_zapuska_net')));
    }
}
