<?php

declare(strict_types=1);

namespace OpenCCK\App\Agent\Tool\Projects;

use DomainException;
use Mercurius\Llm\ToolDefinition;
use OpenCCK\App\Agent\Tool\Args;
use OpenCCK\App\Agent\Tool\GrantKeyed;
use OpenCCK\App\Agent\Tool\Guard\SecretMasker;
use OpenCCK\App\Agent\Tool\Tool;
use OpenCCK\App\Agent\Tool\ToolContext;
use OpenCCK\App\Agent\Tool\ToolResult;
use OpenCCK\Domain\Agent\DangerClass;
use OpenCCK\Domain\Project\Project;
use OpenCCK\Domain\Project\ProjectLevel;
use OpenCCK\Domain\Repository\ConversationRepository;
use OpenCCK\Domain\Repository\ProjectRepository;

use function Mercurius\I18n\t;

/**
 * Keeps projects: the agent writes the card and the tasks itself; a new project, its goal or status, and a
 * participant's relation to it wait for the owner — those calls are irreversible, so the owner gets a card.
 *
 * spec: brief/memory.md#проекты-и-отношение-участника-к-проекту — the agent keeps the card, the goal and the status
 *       change with the owner's confirmation; a relation goes through the owner too and only from the participant's
 *       own words
 */
final class ProjectEditTool implements Tool, GrantKeyed {
    public const NAME = 'project_edit';
    public const ACTIONS = ['create', 'rename', 'goal', 'status', 'card', 'relation', 'activate', 'deactivate', 'share', 'unshare', 'members', 'transfer', 'accept', 'decline', 'rights', 'grant_tool', 'revoke_tool', 'set_limit', 'timezone', 'quiet', 'sleep', 'context'];
    /** the least level an action needs; share and unshare are their own project's business, so no card of the agent owner */
    private const NEEDS = [
        'rename' => ProjectLevel::Write, 'goal' => ProjectLevel::Write, 'status' => ProjectLevel::Write, 'card' => ProjectLevel::Write,
        'relation' => ProjectLevel::Read, 'activate' => ProjectLevel::Read, 'members' => ProjectLevel::Read,
        'share' => ProjectLevel::Full, 'unshare' => ProjectLevel::Read, 'transfer' => ProjectLevel::Owner,
        // spec: brief/participants.md#права-проекта — members see the rights, only the owner of the project changes them
        'rights' => ProjectLevel::Read, 'grant_tool' => ProjectLevel::Owner, 'revoke_tool' => ProjectLevel::Owner, 'set_limit' => ProjectLevel::Owner,
        // spec: brief/scheduler.md#часовой-пояс-проекта — the clock of the project is its owner's to set
        'timezone' => ProjectLevel::Owner, 'quiet' => ProjectLevel::Owner,
        // spec: brief/memory.md#сон-проекта — any member may ask whether the project sleeps; its owner turns it on or off
        'sleep' => ProjectLevel::Read,
        // spec: brief/agent-loop.md#объём-беседы-и-порог-сжатия — members see the limits of the context, its owner sets them
        'context' => ProjectLevel::Read,
    ];
    /** what waits for the owner */
    public const CONFIRMED = ['create', 'goal', 'status', 'relation'];
    public const RELATION_MAX = 1000;

    public function __construct(
        private readonly ProjectRepository $projects,
        private readonly ConversationRepository $conversations,
        /** in a forum a project gets a topic; null — no forum topics */
        private readonly ?\OpenCCK\App\Service\ForumTopics $forum = null,
        /** the participants a project is shared with; null — no sharing here */
        private readonly ?\OpenCCK\Domain\Repository\PrincipalRepository $principals = null,
        /** handing a project over with the taker's consent; null — not here */
        private readonly ?\OpenCCK\App\Service\ProjectTransfers $transfers = null,
    ) {}

    private ?\OpenCCK\App\Peer\ProjectChannels $channels = null;
    private ?\OpenCCK\App\Service\ProjectPolicies $rights = null;
    private ?\OpenCCK\Domain\Repository\ProjectPrefRepository $prefs = null;
    private ?\OpenCCK\Domain\Repository\ProjectSleepRepository $sleep = null;
    private ?\OpenCCK\Domain\Repository\ContextLimitRepository $limits = null;
    /** @var (\Closure(int): void)|null the jobs of a project count their next runs again by its new clock */
    private ?\Closure $rescheduled = null;

    /**
     * spec: brief/scheduler.md#часовой-пояс-проекта
     *
     * @param \Closure(int): void $rescheduled by the id of the project
     */
    public function usePrefs(\OpenCCK\Domain\Repository\ProjectPrefRepository $prefs, \Closure $rescheduled): void {
        $this->prefs = $prefs;
        $this->rescheduled = $rescheduled;
    }

    /** The time zone or the quiet hours of a project; an empty text brings back those of the environment. */
    private function clock(Project $project, string $action, string $text): ToolResult {
        $prefs = $this->prefs;
        if ($prefs === null) {
            return ToolResult::error(t('tool.project_edit.neizvestnoe_deystvie') . $action);
        }
        $now = $prefs->get($project->id);
        $value = $text === '' ? null : $text;
        if ($action === 'timezone') {
            if ($value !== null && \OpenCCK\App\Job\ParticipantClock::parseZone($value) === null) {
                return ToolResult::error(sprintf(t('tool.project_edit.bad_zone'), $value));
            }
            $prefs->save($project->id, $value, $now['quiet']);
        } else {
            $quiet = $value === null ? null : (in_array(mb_strtolower($value), ['none', 'нет', '-'], true) ? '' : $value);
            if ($quiet !== null && $quiet !== '' && \OpenCCK\App\Job\ParticipantClock::parseQuiet($quiet) === null) {
                return ToolResult::error(sprintf(t('tool.project_edit.bad_quiet'), $text));
            }
            $prefs->save($project->id, $now['timezone'], $quiet);
        }
        if ($this->rescheduled !== null) {
            ($this->rescheduled)($project->id);
        }
        $saved = $prefs->get($project->id);
        return ToolResult::ok(sprintf(
            t('tool.project_edit.clock_set'),
            $project->name,
            $saved['timezone'] ?? t('tool.project_edit.from_env'),
            $saved['quiet'] === null ? t('tool.project_edit.from_env') : ($saved['quiet'] === '' ? '—' : $saved['quiet']),
        ));
    }

    /** spec: brief/agent-loop.md#объём-беседы-и-порог-сжатия */
    public function useLimits(\OpenCCK\Domain\Repository\ContextLimitRepository $limits): void {
        $this->limits = $limits;
    }

    /**
     * The limits of the context in the project's conversations: tokens a conversation holds and the percent of them at
     * which compaction starts. Without them — what is set; 0 — back to the agent's value.
     *
     * @param array<string, mixed> $args
     */
    private function context(Project $project, ProjectLevel $level, array $args): ToolResult {
        if ($this->limits === null) {
            return ToolResult::error(t('tool.project_edit.neizvestnoe_deystvie') . 'context');
        }
        [$tokens, $percent] = $this->limits->get(\OpenCCK\Domain\Repository\ContextLimitRepository::PROJECT, $project->id);
        $askedTokens = isset($args['tokens']) && is_int($args['tokens']) ? $args['tokens'] : null;
        $askedPercent = isset($args['percent']) && is_int($args['percent']) ? $args['percent'] : null;
        if ($askedTokens === null && $askedPercent === null) {
            return ToolResult::ok(sprintf(t('tool.project_edit.context_now'), $project->name, \OpenCCK\App\Agent\Context\ContextLimits::describe($tokens, $percent)));
        }
        if (!$level->atLeast(ProjectLevel::Owner)) {
            return ToolResult::error(sprintf(t('tool.project_edit.no_right'), $project->name, ProjectLevel::Owner->label(), $level->label()));
        }
        if ($askedTokens !== null) {
            [$tokens, $error] = \OpenCCK\App\Agent\Context\ContextLimits::tokens($askedTokens);
            if ($error !== null) {
                return ToolResult::error($error);
            }
        }
        if ($askedPercent !== null) {
            [$percent, $error] = \OpenCCK\App\Agent\Context\ContextLimits::percent($askedPercent);
            if ($error !== null) {
                return ToolResult::error($error);
            }
        }
        $this->limits->save(\OpenCCK\Domain\Repository\ContextLimitRepository::PROJECT, $project->id, $tokens, $percent);
        return ToolResult::ok(sprintf(t('tool.project_edit.context_set'), $project->name, \OpenCCK\App\Agent\Context\ContextLimits::describe($tokens, $percent)));
    }

    /** spec: brief/memory.md#сон-проекта */
    public function useSleep(\OpenCCK\Domain\Repository\ProjectSleepRepository $sleep): void {
        $this->sleep = $sleep;
    }

    /**
     * Whether the project sleeps; text on or off turns it, which only the owner of the project (or of the agent) does.
     * Sleep checks and adds to the facts of the project and to nothing else of it.
     */
    private function sleeps(Project $project, ProjectLevel $level, string $text): ToolResult {
        if ($this->sleep === null) {
            return ToolResult::error(t('tool.project_edit.neizvestnoe_deystvie') . 'sleep');
        }
        $turn = mb_strtolower($text);
        if ($turn === '') {
            return ToolResult::ok(sprintf(t($this->sleep->on($project->id) ? 'tool.project_edit.sleep_on' : 'tool.project_edit.sleep_off'), $project->name));
        }
        if (!in_array($turn, ['on', 'off'], true)) {
            return ToolResult::error(t('tool.project_edit.sleep_bad'));
        }
        if (!$level->atLeast(ProjectLevel::Owner)) {
            return ToolResult::error(sprintf(t('tool.project_edit.no_right'), $project->name, ProjectLevel::Owner->label(), $level->label()));
        }
        $this->sleep->set($project->id, $turn === 'on');
        return ToolResult::ok(sprintf(t($turn === 'on' ? 'tool.project_edit.sleep_set_on' : 'tool.project_edit.sleep_set_off'), $project->name));
    }

    /** spec: brief/participants.md#права-проекта */
    public function useRights(\OpenCCK\App\Service\ProjectPolicies $rights): void {
        $this->rights = $rights;
    }

    /**
     * The project's rights: seen by its members, changed by its owner within the owner's own.
     *
     * @param array<string, mixed> $args
     */
    private function rights(Project $project, string $action, array $args): ToolResult {
        if ($this->rights === null) {
            return ToolResult::error(t('tool.project_edit.neizvestnoe_deystvie') . $action);
        }
        $tool = trim(Args::string($args, 'tool') ?? '');
        try {
            if ($action === 'grant_tool' || $action === 'revoke_tool') {
                if ($tool === '') {
                    return ToolResult::error(t('tool.project_edit.needs_tool'));
                }
                $action === 'grant_tool' ? $this->rights->grant($project, $tool) : $this->rights->revoke($project, $tool);
            } elseif ($action === 'set_limit') {
                $value = $args['value'] ?? null;
                $limit = Args::string($args, 'limit') ?? '';
                if (!is_int($value) && !is_float($value)) {
                    return ToolResult::error(t('tool.project_edit.needs_limit'));
                }
                // limits other than the daily spend are whole numbers
                $this->rights->limit($project, $limit, $limit === 'daily_spend_usd' ? (float) $value : (is_float($value) && floor($value) === $value ? (int) $value : $value));
            }
        } catch (\InvalidArgumentException $e) {
            return ToolResult::error($e->getMessage());
        }
        return ToolResult::ok($this->rights->describe($project));
    }

    /** spec: brief/network.md#проект-как-канал-для-агентов — an agent that binds its chat to a project works there from then on */
    public function useChannels(\OpenCCK\App\Peer\ProjectChannels $channels): void {
        $this->channels = $channels;
    }

    public function definition(): ToolDefinition {
        return new ToolDefinition(self::NAME, t('tool.project_edit.vedet_proekty_project_imya_proekta')
            . t('tool.project_edit.proekt_text_tsel_goal_novaya')
            . t('tool.project_edit.repozitorii_servery_stek_prinyatye_reshe')
            . t('tool.project_edit.relation_otnoshenie_sobesednika_k_proekt')
            . t('tool.project_edit.proekt_proektom_etogo_chata_create')
            . ' ' . t('tool.project_edit.rename_about')
            . ' ' . t('tool.project_edit.share_about')
            . ' ' . t('tool.project_edit.transfer_about')
            . ' ' . t('tool.project_edit.secret_about')
            . ' ' . t('tool.project_edit.rights_about')
            . ' ' . t('tool.project_edit.clock_about')
            . ' ' . t('tool.project_edit.sleep_about')
            . ' ' . t('tool.project_edit.context_about'), [
            'type' => 'object',
            'properties' => [
                'action' => ['type' => 'string', 'enum' => self::ACTIONS],
                'project' => ['type' => 'string', 'minLength' => 1, 'maxLength' => Project::NAME_MAX],
                'name' => ['type' => 'string', 'minLength' => 1, 'maxLength' => Project::NAME_MAX],
                'text' => ['type' => 'string', 'maxLength' => Project::CARD_MAX],
                'status' => ['type' => 'string', 'enum' => Project::STATUSES],
                'participant' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 100],
                'access' => ['type' => 'string', 'enum' => ProjectLevel::GIVEN],
                'tool' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 128],
                'limit' => ['type' => 'string', 'enum' => \OpenCCK\App\Service\ProjectPolicies::LIMITS],
                'value' => ['type' => 'number', 'minimum' => 0],
                'tokens' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 10_000_000],
                'percent' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 95],
            ],
            'required' => ['action'],
            'additionalProperties' => false,
        ]);
    }

    public function danger(array $args): DangerClass {
        return in_array(Args::string($args, 'action'), self::CONFIRMED, true) ? DangerClass::Irreversible : DangerClass::Write;
    }

    /**
     * spec: brief/agent-loop.md#разрешения-навсегда — every irreversible action can be allowed for good; the key names the
     * action and its target
     */
    public function grantKey(array $args): ?string {
        $action = Args::string($args, 'action') ?? '';
        if (!in_array($action, self::CONFIRMED, true)) {
            return null;
        }
        $project = mb_strtolower(trim(Args::string($args, 'project') ?? ''));
        return $action === 'create' || $project === '' ? 'project:' . $action : sprintf('project:%s:%s', $action, $project);
    }

    public function describe(array $args): string {
        return sprintf('project_edit %s «%s» %s', Args::string($args, 'action') ?? '', Args::string($args, 'project') ?? '', mb_substr(Args::string($args, 'status') ?? Args::string($args, 'text') ?? '', 0, 200));
    }

    public function untrusted(): bool {
        return false;
    }

    public function timeout(): int {
        return 10;
    }

    public function execute(array $args, ToolContext $context): ToolResult {
        $action = Args::string($args, 'action') ?? '';
        $text = trim(Args::string($args, 'text') ?? '');
        if ($text !== '' && SecretMasker::mask($text) !== $text) {
            return ToolResult::error(t('tool.project_edit.v_proekte_ne_hranyat_sekrety'));
        }
        if ($action === 'create') {
            $name = trim(Args::string($args, 'project') ?? '');
            if ($name === '') {
                return ToolResult::error(t('tool.project_edit.nuzhno_imya_proekta_project'));
            }
            try {
                $project = $this->projects->create($name, mb_substr($text, 0, Project::GOAL_MAX), Project::scopeOf($context->principal));
            } catch (DomainException $e) {
                return ToolResult::error($e->getMessage());
            }
            $meta = $this->conversations->meta($context->conversationId);
            // spec: brief/channels.md#форум-темы--проекты — a new project in a forum gets its own topic
            $topic = $meta === null ? '' : ($this->forum?->created($project, $meta) ?? '');
            return ToolResult::ok(trim(sprintf(t('tool.project_edit.proekt_zaveden'), $project->name, $topic)));
        }
        if ($action === 'deactivate') {
            return $this->deactivate($context);
        }
        if ($action === 'accept' || $action === 'decline') {
            return $this->decide($context, trim(Args::string($args, 'project') ?? ''), $action === 'accept');
        }
        $project = $this->project($args, $context);
        if ($project === null) {
            return ToolResult::error(t('tool.project_edit.proekt_ne_nayden_nazovi_ego'));
        }
        // spec: brief/participants.md#доступ-к-проектам-и-передача — a level says how far a member goes in the project
        $level = $this->levelOf($project, $context->principal);
        $needs = self::NEEDS[$action] ?? ProjectLevel::Owner;
        if ($level === null || !$level->atLeast($needs)) {
            return ToolResult::error(sprintf(t('tool.project_edit.no_right'), $project->name, $needs->label(), $level?->label() ?? '—'));
        }
        return match ($action) {
            'goal' => $this->update($project, $text === '' ? null : mb_substr($text, 0, Project::GOAL_MAX), null, null, t('tool.project_edit.tsel')),
            'status' => in_array(Args::string($args, 'status'), Project::STATUSES, true)
                ? $this->update($project, null, Args::string($args, 'status'), null, t('tool.project_edit.status'))
                : ToolResult::error(t('tool.project_edit.status_proekta_active_paused_ili')),
            'card' => $this->update($project, null, null, $text, t('tool.project_edit.kartochka')),
            'relation' => $this->relation($project, $context, $text),
            'activate' => $this->activate($project, $context),
            'rename' => $this->rename($project, trim(Args::string($args, 'name') ?? '')),
            'share' => $this->share($project, $context, Args::string($args, 'participant') ?? '', Args::string($args, 'access') ?? ''),
            'unshare' => $this->unshare($project, $context, $level, Args::string($args, 'participant') ?? ''),
            'members' => $this->members($project),
            'transfer' => $this->transfer($project, Args::string($args, 'participant') ?? ''),
            'rights', 'grant_tool', 'revoke_tool', 'set_limit' => $this->rights($project, $action, $args),
            'timezone', 'quiet' => $this->clock($project, $action, $text),
            'sleep' => $this->sleeps($project, $level, $text),
            'context' => $this->context($project, $level, $args),
            default => ToolResult::error(t('tool.project_edit.neizvestnoe_deystvie') . $action),
        };
    }

    /** @param array<string, mixed> $args */
    private function project(array $args, ToolContext $context): ?Project {
        $name = Args::string($args, 'project');
        if ($name !== null) {
            return $this->projects->byName($name, Project::scopeOf($context->principal));
        }
        $meta = $this->conversations->meta($context->conversationId);
        $active = $meta === null ? null : $this->projects->active($meta->channel, $meta->chatId, $meta->threadId);
        // spec: brief/participants.md#несколько-пользователей — a participant works with their own projects and those shared with them
        return $active !== null && $this->levelOf($active, $context->principal) !== null ? $active : null;
    }

    private function levelOf(Project $project, \OpenCCK\Domain\Entity\Principal $who): ?ProjectLevel {
        return $project->levelOf($who, $this->projects->memberLevel($project->id, $who->id));
    }

    private function nameOf(?int $principalId): string {
        if ($principalId === null) {
            return t('tool.project_edit.agent_owner');
        }
        $name = $this->principals?->find($principalId)?->displayName;
        return sprintf('%s (#%d)', $name ?? '—', $principalId);
    }

    private function share(Project $project, ToolContext $context, string $participant, string $access): ToolResult {
        if ($this->principals === null || trim($participant) === '' || ProjectLevel::given($access) === null) {
            return ToolResult::error(t('tool.project_edit.share_needs'));
        }
        $who = \OpenCCK\App\Agent\Tool\Messaging\Recipients::participant($this->principals, $participant);
        if (is_string($who)) {
            return ToolResult::error($who);
        }
        if ($project->visibleTo($who)) {
            return ToolResult::error(sprintf(t('tool.project_edit.share_owner'), $this->nameOf($who->id)));
        }
        /** @var 'read'|'write'|'full' $access */
        $this->projects->share($project->id, $who->id, $access, $context->principal->isOwner() ? null : $context->principal->id);
        return ToolResult::ok(sprintf(t('tool.project_edit.shared'), $this->nameOf($who->id), $access, $project->name));
    }

    /** Access is taken back by whoever may give it; a member leaves a project by themselves. */
    private function unshare(Project $project, ToolContext $context, ProjectLevel $level, string $participant): ToolResult {
        if ($this->principals === null || trim($participant) === '') {
            return ToolResult::error(t('tool.project_edit.share_needs'));
        }
        $who = \OpenCCK\App\Agent\Tool\Messaging\Recipients::participant($this->principals, $participant);
        if (is_string($who)) {
            return ToolResult::error($who);
        }
        if ($who->id !== $context->principal->id && !$level->atLeast(ProjectLevel::Full)) {
            return ToolResult::error(sprintf(t('tool.project_edit.no_right'), $project->name, ProjectLevel::Full->label(), $level->label()));
        }
        if (!$this->projects->unshare($project->id, $who->id)) {
            return ToolResult::error(sprintf(t('tool.project_edit.not_member'), $this->nameOf($who->id), $project->name));
        }
        return ToolResult::ok(sprintf(t('tool.project_edit.unshared'), $this->nameOf($who->id), $project->name));
    }

    /** spec: brief/participants.md#доступ-к-проектам-и-передача — the project is offered; it passes only after the taker's yes */
    private function transfer(Project $project, string $participant): ToolResult {
        if ($this->transfers === null || $this->principals === null) {
            return ToolResult::error(t('tool.project_edit.transfer_off'));
        }
        if (trim($participant) === '') {
            return ToolResult::error(t('tool.project_edit.transfer_needs'));
        }
        $who = \OpenCCK\App\Agent\Tool\Messaging\Recipients::participant($this->principals, $participant);
        if (is_string($who)) {
            return ToolResult::error($who);
        }
        try {
            $this->transfers->offer($project, $who);
        } catch (DomainException $e) {
            return ToolResult::error($e->getMessage());
        }
        return ToolResult::ok(sprintf(t('tool.project_edit.transfer_offered'), $project->name, $this->nameOf($who->id)));
    }

    /** The taker's own word on a project offered to them: the one of this name, or the only one that waits. */
    private function decide(ToolContext $context, string $name, bool $accept): ToolResult {
        if ($this->transfers === null) {
            return ToolResult::error(t('tool.project_edit.transfer_off'));
        }
        $waiting = $this->transfers->waitingFor($context->principal);
        $found = array_values(array_filter($waiting, static fn(array $w): bool => $name === '' || mb_strtolower($w[1]->name) === mb_strtolower($name)));
        if (count($found) !== 1) {
            $names = implode(', ', array_map(static fn(array $w): string => '«' . $w[1]->name . '»', $waiting));
            return ToolResult::error(sprintf(t('tool.project_edit.transfer_none'), $name, $names === '' ? t('tool.project_edit.members_none') : $names));
        }
        try {
            return ToolResult::ok($this->transfers->decide($found[0][0]->id, $context->principal, $accept));
        } catch (DomainException $e) {
            return ToolResult::error($e->getMessage());
        }
    }

    private function members(Project $project): ToolResult {
        $members = array_map(fn(array $m): string => sprintf('%s — %s', $this->nameOf($m['principalId']), $m['access']), $this->projects->members($project->id));
        return ToolResult::ok(sprintf(t('tool.project_edit.members'), $project->name, $this->nameOf($project->ownerId), $members === [] ? t('tool.project_edit.members_none') : implode('; ', $members)));
    }

    /** spec: brief/participants.md#доступ-к-проектам-и-передача — a participant decides whether their chat is bound to a project */
    private function deactivate(ToolContext $context): ToolResult {
        $meta = $this->conversations->meta($context->conversationId);
        if ($meta === null || $this->projects->active($meta->channel, $meta->chatId, $meta->threadId) === null) {
            return ToolResult::error(t('tool.project_edit.not_bound'));
        }
        $this->projects->activate($meta->channel, $meta->chatId, null, $meta->threadId);
        if ($this->channels !== null && $meta->channel === \OpenCCK\App\Peer\A2aServer::CHANNEL && $context->principal->isAgent()) {
            $this->channels->leave($context->principal);
        }
        return ToolResult::ok(t('tool.project_edit.unbound'));
    }

    /** spec: brief/channels.md#форум-темы--проекты — a new name for the project and its forum topics */
    private function rename(Project $project, string $name): ToolResult {
        if ($name === '' || mb_strlen($name) > Project::NAME_MAX) {
            return ToolResult::error(sprintf(t('tool.project_edit.rename_needs_name'), Project::NAME_MAX));
        }
        try {
            $this->projects->rename($project->id, $name);
        } catch (DomainException $e) {
            return ToolResult::error($e->getMessage());
        }
        $this->forum?->renamed($project->id, $name);
        return ToolResult::ok(sprintf(t('tool.project_edit.renamed'), $project->name, $name));
    }

    private function update(Project $project, ?string $goal, ?string $status, ?string $card, string $what): ToolResult {
        $this->projects->update($project->id, $goal, $status, $card);
        if ($status !== null) {
            $this->forum?->statusChanged($project, $status);
        }
        return ToolResult::ok(sprintf(t('tool.project_edit.proekta_obnovlena'), $what, $project->name));
    }

    private function relation(Project $project, ToolContext $context, string $text): ToolResult {
        if ($text === '' || mb_strlen($text) > self::RELATION_MAX) {
            return ToolResult::error(sprintf(t('tool.project_edit.otnoshenie_slovami_sobesednika_do_simvol'), self::RELATION_MAX));
        }
        $this->projects->setRelation($project->id, $context->principal->id, $text);
        return ToolResult::ok(sprintf(t('tool.project_edit.otnoshenie_k_proektu_zapisano'), $project->name));
    }

    private function activate(Project $project, ToolContext $context): ToolResult {
        $meta = $this->conversations->meta($context->conversationId);
        if ($meta === null) {
            return ToolResult::error(t('tool.project_edit.beseda_ne_naydena'));
        }
        $this->projects->activate($meta->channel, $meta->chatId, $project->id, $meta->threadId);
        // spec: brief/network.md#проект-как-канал-для-агентов — an agent of the network enters the project for its next tasks too
        if ($this->channels !== null && $meta->channel === \OpenCCK\App\Peer\A2aServer::CHANNEL && $context->principal->isAgent()) {
            $this->channels->enter($context->principal, $project);
            return ToolResult::ok(sprintf(t('tool.project_edit.agent_entered'), $project->name));
        }
        return ToolResult::ok(sprintf(t('tool.project_edit.proekt_etogo_chata_kartochka_pridet'), $project->name));
    }
}
