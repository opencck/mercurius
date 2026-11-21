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
    public const ACTIONS = ['create', 'rename', 'goal', 'status', 'card', 'relation', 'activate', 'deactivate', 'share', 'unshare', 'members', 'transfer', 'accept', 'decline', 'share_secret', 'unshare_secret'];
    /** the least level an action needs; share and unshare are their own project's business, so no card of the agent owner */
    private const NEEDS = [
        'rename' => ProjectLevel::Write, 'goal' => ProjectLevel::Write, 'status' => ProjectLevel::Write, 'card' => ProjectLevel::Write,
        'relation' => ProjectLevel::Read, 'activate' => ProjectLevel::Read, 'members' => ProjectLevel::Read,
        'share' => ProjectLevel::Full, 'unshare' => ProjectLevel::Read, 'transfer' => ProjectLevel::Owner,
        'share_secret' => ProjectLevel::Full, 'unshare_secret' => ProjectLevel::Full,
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
            . ' ' . t('tool.project_edit.secret_about'), [
            'type' => 'object',
            'properties' => [
                'action' => ['type' => 'string', 'enum' => self::ACTIONS],
                'project' => ['type' => 'string', 'minLength' => 1, 'maxLength' => Project::NAME_MAX],
                'name' => ['type' => 'string', 'minLength' => 1, 'maxLength' => Project::NAME_MAX],
                'text' => ['type' => 'string', 'maxLength' => Project::CARD_MAX],
                'status' => ['type' => 'string', 'enum' => Project::STATUSES],
                'participant' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 100],
                'access' => ['type' => 'string', 'enum' => ProjectLevel::GIVEN],
                'secret' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 64],
                'site' => ['type' => 'string', 'minLength' => 3, 'maxLength' => 253],
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
            'share_secret' => $this->shareSecret($project, $context, trim(Args::string($args, 'secret') ?? ''), strtolower(trim(Args::string($args, 'site') ?? ''))),
            'unshare_secret' => $this->unshareSecret($project, $context, trim(Args::string($args, 'secret') ?? '')),
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

    private ?\OpenCCK\App\Vault\Vault $vault = null;
    private ?\OpenCCK\Domain\Repository\ProjectLinkRepository $links = null;

    /** spec: brief/participants.md#ресурсы-проекта — a secret is given to a project by its author, for one site */
    public function useSecrets(\OpenCCK\App\Vault\Vault $vault, \OpenCCK\Domain\Repository\ProjectLinkRepository $links): void {
        $this->vault = $vault;
        $this->links = $links;
    }

    /**
     * Gives a secret to the project: those who work in it use the secret in requests to this site and never see it.
     *
     * spec: brief/participants.md#ресурсы-проекта
     */
    private function shareSecret(Project $project, ToolContext $context, string $name, string $site): ToolResult {
        $id = $this->vault?->id($name);
        if ($this->vault === null || $this->links === null || $id === null || !$this->vault->readable($name, $context->principal)) {
            return ToolResult::error(sprintf(t('tool.project_edit.secret_not_yours'), $name));
        }
        if (preg_match(\OpenCCK\Domain\Mail\Mailbox::HOST_PATTERN, $site) !== 1) {
            return ToolResult::error(t('tool.project_edit.secret_needs_site'));
        }
        $this->links->bindSecret($id, $project->id, $site);
        return ToolResult::ok(sprintf(t('tool.project_edit.secret_shared'), $name, $project->name, $site));
    }

    private function unshareSecret(Project $project, ToolContext $context, string $name): ToolResult {
        $id = $this->vault?->id($name);
        $binding = $id === null ? null : $this->links?->secretBinding($id);
        if ($this->vault === null || $this->links === null || $id === null || $binding === null || $binding['projectId'] !== $project->id || !$this->vault->readable($name, $context->principal)) {
            return ToolResult::error(sprintf(t('tool.project_edit.secret_not_shared'), $name, $project->name));
        }
        $this->links->unbindSecret($id);
        return ToolResult::ok(sprintf(t('tool.project_edit.secret_unshared'), $name, $project->name));
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
