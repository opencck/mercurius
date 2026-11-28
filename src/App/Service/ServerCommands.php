<?php

declare(strict_types=1);

namespace OpenCCK\App\Service;

use DomainException;
use OpenCCK\App\Agent\Tool\Guard\SecretMasker;
use OpenCCK\App\Vault\Vault;
use OpenCCK\Domain\Entity\Member;
use OpenCCK\Domain\Repository\CommandAuditRepository;
use SensitiveParameter;

use function Mercurius\I18n\t;

/**
 * Commands for secrets, servers and the journal. A secret comes only by command, never through the model; the message
 * that carried it is deleted by the caller.
 *
 * spec: brief/servers.md#как-сделано-хранилище-реестр-и-ssh — a secret that appeared in Telegram is deleted from the chat and kept in the vault
 */
final class ServerCommands {
    private const AUDIT = 15;

    public function __construct(
        private readonly Vault $vault,
        private readonly ?\OpenCCK\App\Server\SshEntries $entries,
        private readonly CommandAuditRepository $audit,
        private readonly ?\OpenCCK\Domain\Repository\SshHostKeyRepository $keys = null,
    ) {}

    /** "/secret <name> <value>" stores, "/secret - <name>" removes. */
    private ?\OpenCCK\Domain\Repository\ProjectRepository $projects = null;

    /** @var (\Closure(string): void)|null told the name of a secret just saved */
    private ?\Closure $onSaved = null;

    /**
     * spec: brief/skills-mcp.md#подстановка-секретов — a saved secret wakes at once the MCP connections that waited for it
     *
     * @param \Closure(string): void $onSaved
     */
    public function useSecretSaved(\Closure $onSaved): void {
        $this->onSaved = $onSaved;
    }

    private function saved(string $name): void {
        if ($this->onSaved !== null) {
            ($this->onSaved)($name);
        }
    }

    /** spec: brief/participants.md#секреты-проекта — "/secret <project>: …" keeps a secret of a project */
    public function useProjects(\OpenCCK\Domain\Repository\ProjectRepository $projects): void {
        $this->projects = $projects;
    }

    private ?\OpenCCK\Domain\Repository\SecretInheritanceRepository $inherit = null;
    /** @var (\Closure(): ?int)|null the owner of the agent: the owner of a project that has no owner id */
    private ?\Closure $agentOwner = null;

    /**
     * spec: brief/skills-mcp.md#подстановка-секретов — "/secret … inherit …" turns the wider layers on and off
     *
     * @param (\Closure(): ?int)|null $agentOwner the owner of the agent
     */
    public function useInheritance(\OpenCCK\Domain\Repository\SecretInheritanceRepository $inherit, ?\Closure $agentOwner = null): void {
        $this->inherit = $inherit;
        $this->agentOwner = $agentOwner;
    }

    /**
     * "/secret *: <name> <value>" keeps a secret of the agent, "*: - <name>" removes it, "*:" lists the names. Only the
     * owners of the agent: it is the widest layer, inherited by the contexts they turn it on for.
     *
     * spec: brief/skills-mcp.md#подстановка-секретов
     */
    private function agentSecret(Member $principal, #[SensitiveParameter] string $rest): string {
        if (!$principal->isOwner()) {
            return t('service.server_commands.agent_secret_owner_only');
        }
        if ($rest === '') {
            $names = $this->vault->agentNames();
            return $names === [] ? t('service.server_commands.agent_secrets_none') : t('service.server_commands.agent_secrets') . implode(', ', array_column($names, 'name'));
        }
        if (str_starts_with($rest, '- ')) {
            $name = trim(substr($rest, 2));
            return sprintf(t($this->vault->removeAgent($name) ? 'service.server_commands.agent_secret_removed' : 'service.server_commands.agent_secret_none'), $name);
        }
        $parts = preg_split('~\s+~', $rest, 2) ?: [];
        if (count($parts) < 2 || trim($parts[1]) === '') {
            return t('service.server_commands.agent_secret_usage');
        }
        try {
            $existed = $this->vault->putAgent($parts[0], trim($parts[1]), $principal);
        } catch (DomainException $e) {
            return $e->getMessage();
        }
        $this->saved($parts[0]);
        return sprintf(t($existed ? 'service.server_commands.agent_secret_updated' : 'service.server_commands.agent_secret_saved'), $parts[0]);
    }

    /**
     * "inherit" — what a context inherits; "inherit owner|agent on|off" turns a layer on or off. A project inherits its
     * owner's secrets when its owner says so, the agent's when an owner of the agent does; a member's personal context
     * inherits the agent's when an owner of the agent turns it on for them ("inherit agent on <number>", without a
     * number — for oneself).
     *
     * spec: brief/skills-mcp.md#подстановка-секретов — off by default
     */
    private function inherit(Member $principal, ?\OpenCCK\Domain\Project\Project $project, string $rest): string {
        $store = $this->inherit;
        if ($store === null) {
            return t('service.server_commands.inherit_off');
        }
        $parts = preg_split('~\s+~', trim($rest)) ?: [];
        // another member's context is named by number, and only by an owner of the agent
        $other = $principal->isOwner() && isset($parts[3]) && ctype_digit($parts[3]) ? (string) (int) $parts[3] : (string) $principal->id;
        $context = $project === null ? 'member:' . $other : 'project:' . $project->id;
        if (count($parts) === 1) {
            return $this->inheritState($context, $project);
        }
        $layer = $parts[1] ?? '';
        $on = ($parts[2] ?? '') === 'on';
        if (!in_array($layer, $project === null ? ['agent'] : ['owner', 'agent'], true) || !in_array($parts[2] ?? '', ['on', 'off'], true)) {
            return t($project === null ? 'service.server_commands.inherit_usage_member' : 'service.server_commands.inherit_usage_project');
        }
        // the owner's own secrets go to their project by their word; the agent's — only by an owner of the agent
        // a project of the owner of the agent has no owner id: its owner is the owner of the agent
        $owner = $project === null ? null : ($project->ownerId ?? ($this->agentOwner === null ? null : ($this->agentOwner)()));
        $may = $layer === 'owner' ? ($owner !== null && $owner === $principal->id) : $principal->isOwner();
        if (!$may) {
            return t($layer === 'owner' ? 'service.server_commands.inherit_owner_only' : 'service.server_commands.inherit_agent_only');
        }
        $store->set($context, $layer === 'owner' ? 'owner' : 'agent', $on, $layer === 'owner' ? $principal->id : null);
        return $this->inheritState($context, $project);
    }

    private function inheritState(string $context, ?\OpenCCK\Domain\Project\Project $project): string {
        $now = $this->inherit?->of($context) ?? ['owner' => false, 'agent' => false];
        $word = static fn(bool $on): string => t($on ? 'service.server_commands.inherit_on_word' : 'service.server_commands.inherit_off_word');
        return $project === null
            ? sprintf(t('service.server_commands.inherit_state_member'), $context, $word($now['agent']))
            : sprintf(t('service.server_commands.inherit_state_project'), $project->name, $word($now['owner']), $word($now['agent']));
    }

    /**
     * The project a command names before a colon: a project the participant sees by that name; null — the text is a
     * personal secret (its value may hold a colon). A project shared with the participant is named too, to be refused.
     *
     * @return array{\OpenCCK\Domain\Project\Project, string}|null the project and the rest of the command
     */
    private function projectOf(Member $principal, string $argument): ?array {
        if ($this->projects === null || preg_match('~^([^:\n]{1,100}):(?:\s+(.*))?$~su', $argument, $m) !== 1) {
            return null;
        }
        $project = $this->projects->byName(trim($m[1]), \OpenCCK\Domain\Project\Project::scopeOf($principal));
        return $project === null ? null : [$project, trim($m[2] ?? '')];
    }

    /**
     * "/secret <project>: <name> <value> <site>" keeps or updates a secret of a project, "… - <name>" removes it,
     * "<project>:" alone lists the names. Only the owner of the project and the owner of the agent manage them.
     *
     * spec: brief/participants.md#секреты-проекта
     */
    private function projectSecret(Member $principal, \OpenCCK\Domain\Project\Project $project, #[SensitiveParameter] string $rest): string {
        if ($rest === 'inherit' || str_starts_with($rest, 'inherit ')) {
            return $this->inherit($principal, $project, $rest);
        }
        if (!$project->visibleTo($principal)) {
            return sprintf(t('service.server_commands.project_secret_owner_only'), $project->name);
        }
        if ($rest === '') {
            $names = $this->vault->projectNames($project->id);
            return $names === [] ? sprintf(t('service.server_commands.project_secrets_none'), $project->name)
                : sprintf(t('service.server_commands.project_secrets'), $project->name) . implode("\n", array_map(static fn(array $s): string => sprintf('%s — %s', $s['name'], $s['site']), $names));
        }
        if (str_starts_with($rest, '- ')) {
            $name = trim(substr($rest, 2));
            return $this->vault->removeProject($name, $project->id) ? sprintf(t('service.server_commands.project_secret_removed'), $name, $project->name) : sprintf(t('service.server_commands.project_secret_none'), $name, $project->name);
        }
        // the value keeps its own spaces and lines: it is what stands between the name and the site
        if (preg_match('~^(\S+)\s+(.+)\s+(\S+)$~su', $rest, $m) !== 1) {
            return t('service.server_commands.project_secret_usage');
        }
        [, $name, $value, $site] = $m;
        $value = trim($value);
        $site = strtolower(trim($site, '/'));
        if (preg_match('~^(?=.{3,253}$)[a-z0-9-]+(\.[a-z0-9-]+)+$~', $site) !== 1) {
            return sprintf(t('service.server_commands.project_secret_bad_site'), $site);
        }
        try {
            $existed = $this->vault->putProject($project->id, $name, $site, $value, $principal);
            $this->saved($name);
        } catch (DomainException $e) {
            return $e->getMessage();
        }
        return sprintf(t($existed ? 'service.server_commands.project_secret_updated' : 'service.server_commands.project_secret_saved'), $name, $project->name, $site);
    }

    public function secret(Member $principal, #[SensitiveParameter] string $argument): string {
        $argument = trim($argument);
        if ($argument === '*:' || str_starts_with($argument, '*: ')) {
            return $this->agentSecret($principal, trim(substr($argument, 2)));
        }
        if ($argument === 'inherit' || str_starts_with($argument, 'inherit ')) {
            return $this->inherit($principal, null, $argument);
        }
        $named = $this->projectOf($principal, $argument);
        if ($named !== null) {
            return $this->projectSecret($principal, $named[0], $named[1]);
        }
        if (str_starts_with($argument, '- ')) {
            $name = trim(substr($argument, 2));
            return $this->vault->remove($name, $principal) ? sprintf(t('service.server_commands.sekret_udalen'), $name) : sprintf(t('service.server_commands.sekreta_net_ili_on_ne'), $name);
        }
        $parts = preg_split('~\s+~', $argument, 2) ?: [];
        if (count($parts) < 2 || $parts[1] === '') {
            return t('service.server_commands.format_secret_imya_znachenie_soobschenie');
        }
        [$name, $value] = $parts;
        $kind = str_starts_with($value, '-----BEGIN') ? 'private_key' : 'password';
        $existed = $this->vault->id($name) !== null;
        try {
            // spec: brief/servers.md#как-сделано-хранилище-реестр-и-ssh — the same command with a new value updates the secret
            $this->vault->put($name, $kind, $value, $principal, true);
        } catch (DomainException $e) {
            return $e->getMessage();
        }
        $this->saved($name);
        return sprintf(t($existed ? 'service.server_commands.secret_updated' : 'service.server_commands.sekret_sohranen_soobschenie_s_nim'), $name, $kind === 'private_key' ? t('service.server_commands.zakrytyy_klyuch') : t('service.server_commands.parol'));
    }

    /**
     * Keeps the text of a message that carried a secret; returns the name it is kept under, null when it could not be.
     */
    public function stash(Member $principal, #[SensitiveParameter] string $text, string $messageId): ?string {
        $name = 'msg-' . (preg_replace('~[^A-Za-z0-9]~', '', $messageId) ?? '');
        try {
            $this->vault->put($name, 'token', $text, $principal);
            return $name;
        } catch (DomainException) {
            return null;
        }
    }

    public function secrets(Member $principal, string $argument = ''): string {
        if (!$this->vault->enabled()) {
            return t('service.server_commands.hranilische_sekretov_vyklyucheno_v_env');
        }
        if (trim($argument) === '*') {
            return $this->agentSecret($principal, '');
        }
        // spec: brief/participants.md#секреты-проекта — "/secrets <project>" lists the names of a project's secrets for its owner
        $named = trim($argument) === '' ? null : $this->projectOf($principal, trim($argument) . ':');
        if ($named !== null) {
            return $this->projectSecret($principal, $named[0], '');
        }
        $names = $this->vault->names($principal);
        return $names === [] ? t('service.server_commands.sekretov_net_secret_imya_znachenie') : t('service.server_commands.sekrety_tolko_imena') . implode("\n", array_map(static fn(array $s): string => sprintf('%s — %s', $s['name'], $s['kind']), $names));
    }

    /**
     * "/server - <name>" removes a server from the registry; the owner typed it, so no card.
     *
     * spec: brief/servers.md#как-сделано-хранилище-реестр-и-ssh — the registry is changed in the dialogue
     */
    public function server(string $argument): string {
        // spec: brief/servers.md#ssh-как-предустановленный-mcp — a server is an entry of .mcp.json: it is changed and
        //       removed as an entry
        return t('service.server_commands.server_usage');
    }

    /** Every SSH entry of our MCP, whoever keeps it, with the state of its host key. */
    public function servers(): string {
        $all = $this->entries?->all() ?? [];
        if ($all === []) {
            return t('service.server_commands.serverov_v_reestre_net_poprosite');
        }
        return t('service.server_commands.servery') . implode("\n", array_map(fn(\OpenCCK\App\Server\SshTarget $s): string => sprintf(
            '%s (%s) — %s@%s (%s)%s',
            $s->name,
            $s->context,
            $s->user,
            $s->address(),
            $s->group,
            $this->keys?->of($s->key, $s->address())?->hostKey === null ? t('service.server_commands.klyuch_hosta_ne_podtverzhden') : '',
        ), $all));
    }

    public function audit(): string {
        $broken = $this->audit->verify();
        $lines = array_map(
            static fn(array $r): string => sprintf('#%d %s %s%s [%s, %s%s] %s', $r['id'], $r['at'], $r['server'], $r['who'] === null ? '' : ' (' . $r['who'] . ')', $r['class'], $r['event'], $r['exit'] === null ? '' : t('service.server_commands.kod') . $r['exit'], mb_substr(SecretMasker::mask($r['command']), 0, 200)),
            $this->audit->recent(self::AUDIT),
        );
        $chain = $broken === null ? t('service.server_commands.tsepochka_zhurnala_tsela') : sprintf(t('service.server_commands.tsepochka_zhurnala_narushena_na_zapisi'), $broken);
        return $lines === [] ? t('service.server_commands.zhurnal_komand_pust') . $chain : t('service.server_commands.poslednie_komandy') . implode("\n", $lines) . "\n\n" . $chain;
    }
}
