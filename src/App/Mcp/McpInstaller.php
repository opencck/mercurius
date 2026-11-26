<?php

declare(strict_types=1);

namespace OpenCCK\App\Mcp;

use Amp\NullCancellation;
use Closure;
use DomainException;
use InvalidArgumentException;
use OpenCCK\App\Agent\Sandbox\Sandbox;
use OpenCCK\App\Agent\Sandbox\SandboxFailure;
use OpenCCK\App\Agent\Tool\Guard\SecretMasker;
use OpenCCK\Domain\Entity\Member;
use OpenCCK\Domain\Mcp\McpInstall;
use OpenCCK\Domain\Mcp\McpServer;
use OpenCCK\Domain\Repository\McpInstallRepository;
use RuntimeException;
use Throwable;

use function Mercurius\I18n\t;

/**
 * Installing an MCP server the agent planned itself: the sources into the sandbox, then the plan — steps and the entry
 * of .mcp.json — runs by the install's own user without secrets, a trial start lists the tools, and the approval of
 * that set with its danger classes pins the revision and writes the entry into the context that asked for it.
 *
 * spec: brief/skills-mcp.md#установка-и-обновление
 * ref: references/hermes-agent/hermes_cli/mcp_catalog.py@e96221fa:L778-L848 (_install_entry) — clone at a pinned
 *      revision, setup commands, the entry with ${INSTALL_DIR}, a trial connection and the choice of tools; here the
 *      plan comes from the agent instead of a manifest, the steps run in the sandbox as a user of their own, and the
 *      approver also sets the classes of the tools
 */
final class McpInstaller {
    /** the placeholder of the entry that becomes the folder of the code */
    public const DIR = '${INSTALL_DIR}';
    private const STEPS_MAX = 12;
    private const STEP_MAX = 1000;
    private const STEPS_SECONDS = 600;

    /** @var array<string, array{context: string, revision: string, entry: array<string, mixed>, tools: list<array{name: string, description: string, schema: array<array-key, mixed>, annotations: array<array-key, mixed>}>, by: int, steps: list<string>, update: bool}> */
    private array $pending = [];
    /** @var list<string> the installs of the delivery: they update with the agent, not from their source */
    private array $bundled = [];

    /**
     * @param Closure(int, McpServer): list<array{name: string, description: string, schema: array<array-key, mixed>, annotations: array<array-key, mixed>}> $trial
     *        a start of the server as the install's user, its tools listed, the process closed
     * @param Closure(McpEntry, string): McpRendered $render the entry with the secrets of the context put in
     * @param Closure(string, McpEntry, Member): string $persist the entry written into the context's file and approved
     * @param (Closure(list<array{string, string, ?string}>): ?string)|null $osv a verdict on the dependencies; null — clean
     */
    public function __construct(
        private readonly McpInstallRepository $installs,
        private readonly McpSources $sources,
        private readonly Sandbox $sandbox,
        private readonly Closure $trial,
        private readonly Closure $render,
        private readonly Closure $persist,
        private readonly ?Closure $osv = null,
    ) {}

    /** @param list<string> $names the MCPs of the delivery */
    public function useBundled(array $names): void {
        $this->bundled = $names;
    }

    /**
     * Installs an MCP of the delivery from the sandbox image as an install of the owner of the agent, with no card: the
     * code is ours, its tools take the classes it marks. A new revision goes next to the one in use and the link moves
     * to it; an install of the same name from another source is left alone. A sample entry, when given, goes into the
     * owner's file — on a clean install, waiting for its secrets.
     *
     * spec: brief/skills-mcp.md#предустановленные-mcp-ssh-и-mail
     *
     * @param array{name: string, args: list<string>, env: array<string, string>}|null $sample the entry's name, args after the code's own, env
     * @return string what was done, for the log
     */
    public function preinstall(Member $owner, McpBundle $bundle, ?array $sample): string {
        $install = $this->installs->find($bundle->name);
        if ($install !== null && !$bundle->sameSource($install->source)) {
            return 'the name is taken by ' . $install->source;
        }
        if ($install !== null && $install->revision === $bundle->revision && $this->installs->environment($bundle->name) !== McpInstall::RESTORED) {
            return 'at ' . $bundle->revision;
        }
        $install ??= $this->installs->add($bundle->name, $bundle->source, $owner->id);
        $revision = $bundle->revision;
        try {
            $this->sources->link($install);
            $copy = 'chmod 755 . && mkdir -p releases && chmod 755 releases && rm -rf ' . self::quote('releases/' . $revision)
                . ' && cp -R ' . self::quote($bundle->dir()) . ' ' . self::quote('releases/' . $revision)
                . ' && chmod -R o+rX ' . self::quote('releases/' . $revision);
            $outcome = $this->sandbox->exec($install->holder(), McpSources::limits(), 'mcp-install', $copy, '.', self::STEPS_SECONDS, new NullCancellation());
            if ($outcome->exitCode !== 0) {
                return 'not copied: ' . trim(mb_substr($outcome->stderr, -600));
            }
            $trial = McpEntry::fromArray($bundle->name, self::placed($bundle->entry, $install->path() . '/releases/' . $revision));
            $entry = McpEntry::fromArray($bundle->name, self::placed($bundle->entry, $install->path() . '/current'));
        } catch (DomainException | SandboxFailure | InvalidArgumentException $e) {
            return 'not copied: ' . $e->getMessage();
        }
        $tools = $this->tryStart($install, ($this->render)($trial, 'member:' . $owner->id));
        if (is_string($tools)) {
            return $tools;
        }
        $switched = $this->switchTo($install, $revision);
        if ($switched !== null) {
            return $switched;
        }
        $classes = [];
        foreach ($tools as $tool) {
            $classes[$tool['name']] = self::hinted($tool['annotations']);
        }
        $this->installs->setRevision($bundle->name, $revision, self::setHash($tools), $classes, $entry->toArray());
        $this->installs->setSteps($bundle->name, []);
        $this->installs->setLatest($bundle->name, null);
        // the dependencies of a bundle come with the image: its print is the revision
        $this->installs->setEnvironment($bundle->name, $revision);
        if ($sample !== null) {
            try {
                $own = McpEntry::fromArray($sample['name'], ['command' => $entry->command, 'args' => [...$entry->args, ...$sample['args']], 'env' => $sample['env']]);
                ($this->persist)('member:' . $owner->id, $own, $owner);
            } catch (Throwable $e) {
                return 'installed ' . $revision . '; the sample entry was not written: ' . $e->getMessage();
            }
        }
        return 'installed ' . $revision;
    }

    /**
     * The installs whose dependencies were built in other environments of the image (Node, Python, PHP, Go) build them
     * again by their kept steps in the revision in use. The first look only remembers the environments; the installs
     * of the delivery come with their dependencies and are left alone.
     *
     * spec: brief/skills-mcp.md#установка-и-обновление
     *
     * @return list<array{int, string, ?string}> installer, name, why the build failed (null — built)
     */
    public function rebuildChanged(string $environment): array {
        $told = [];
        foreach ($this->installs->all() as $install) {
            if ($install->revision === null || $install->steps === [] || in_array($install->name, $this->bundled, true)) {
                continue;
            }
            $was = $this->installs->environment($install->name);
            $this->installs->setEnvironment($install->name, $environment);
            if ($was === null || $was === $environment) {
                continue;
            }
            $told[] = [$install->installedBy, $install->name, $this->runSteps($install, $install->revision, $install->steps)];
        }
        return $told;
    }

    /**
     * The dependencies of the revision in use built again by the kept steps: after a native module was built for
     * another Node, or anything else the steps make.
     *
     * spec: brief/skills-mcp.md#установка-и-обновление
     */
    public function rebuild(Member $who, string $name): string {
        if (in_array($name, $this->bundled, true)) {
            return sprintf(t('mcp.install.bundled'), $name);
        }
        $install = $this->installs->find($name);
        if ($install === null || $install->revision === null) {
            return sprintf(t('mcp.install.not_installed'), $name);
        }
        if (!$this->mayChange($who, $install)) {
            return sprintf(t('mcp.install.not_yours'), $name);
        }
        if ($install->steps === []) {
            return sprintf(t('mcp.install.nothing_to_build'), $name);
        }
        return $this->runSteps($install, $install->revision, $install->steps) ?? sprintf(t('mcp.install.rebuilt'), $name, $install->revision);
    }

    /**
     * Brings the sources and shows them: the agent reads them to make a plan. A name taken by someone else's install
     * is refused; the owner of the agent may work with any.
     *
     * @param array{string, string}|null $archive file name and bytes of an archive
     */
    public function fetch(Member $who, string $name, ?string $url, ?array $archive): string {
        if (preg_match(McpInstall::NAME_PATTERN, $name) !== 1) {
            return t('mcp.install.bad_name');
        }
        $install = $this->installs->find($name);
        if ($install !== null && !$this->mayChange($who, $install)) {
            return sprintf(t('mcp.install.not_yours'), $name);
        }
        try {
            $install ??= $this->installs->add($name, $url ?? 'archive:' . ($archive[0] ?? ''), $who->id);
            $fetched = $url !== null ? $this->sources->fromGit($install, $url) : ($archive !== null ? $this->sources->fromArchive($install, $archive[0], $archive[1]) : throw new DomainException(t('mcp.install.no_source')));
        } catch (DomainException $e) {
            return $e->getMessage();
        }
        $this->installs->setLatest($name, $fetched->revision);
        return sprintf(t('mcp.install.fetched'), $name, $fetched->revision) . "\n\n" . $fetched->overview;
    }

    /**
     * Runs the plan: steps in the folder of the revision, then a trial start with the secrets of the context.
     *
     * @param list<string> $steps
     * @param array<array-key, mixed> $entry command, args, env with {SECRET} names and ${INSTALL_DIR}
     */
    public function install(Member $who, string $context, string $name, array $steps, array $entry): string {
        $install = $this->installs->find($name);
        if ($install === null || $install->latest === null) {
            return sprintf(t('mcp.install.not_fetched_yet'), $name);
        }
        if (!$this->mayChange($who, $install)) {
            return sprintf(t('mcp.install.not_yours'), $name);
        }
        if (count($steps) > self::STEPS_MAX || array_filter($steps, static fn(string $s): bool => strlen($s) > self::STEP_MAX || trim($s) === '') !== []) {
            return sprintf(t('mcp.install.bad_steps'), self::STEPS_MAX, self::STEP_MAX);
        }
        $revision = $install->latest;
        $release = $install->path() . '/releases/' . $revision;
        try {
            $trial = McpEntry::fromArray($name, self::placed($entry, $release));
            McpEntry::fromArray($name, self::placed($entry, $install->path() . '/current'));
        } catch (InvalidArgumentException $e) {
            return $e->getMessage();
        }
        if ($trial->command === null) {
            return t('mcp.install.command_only');
        }
        // the secrets first: a missing one stops here, before anything runs
        $rendered = ($this->render)($trial, $context);
        if ($rendered->missing !== []) {
            return sprintf(t('mcp.install.secrets_missing'), implode(', ', $rendered->missing), $context);
        }
        $verdict = $this->osvVerdict($install, $revision);
        if ($verdict !== null) {
            return $verdict;
        }
        $failed = $this->runSteps($install, $revision, $steps);
        if ($failed !== null) {
            return $failed;
        }
        $tools = $this->tryStart($install, $rendered);
        if (is_string($tools)) {
            return $tools;
        }
        $this->pending[$name] = ['context' => $context, 'revision' => $revision, 'entry' => self::placed($entry, $install->path() . '/current'), 'tools' => $tools, 'by' => $who->id, 'steps' => $steps, 'update' => false];
        return $this->toApprove($name, $revision, $tools);
    }

    /**
     * A new revision of the source next to the one in use: the kept steps again, a trial start, and the same set of
     * tools switches at once with its approved classes; another set waits for an approval.
     *
     * spec: brief/skills-mcp.md#установка-и-обновление
     */
    public function update(Member $who, string $name): string {
        if (in_array($name, $this->bundled, true)) {
            return sprintf(t('mcp.install.bundled'), $name);
        }
        $install = $this->installs->find($name);
        if ($install === null || $install->revision === null || $install->entry === null) {
            return sprintf(t('mcp.install.not_installed'), $name);
        }
        if (!$this->mayChange($who, $install)) {
            return sprintf(t('mcp.install.not_yours'), $name);
        }
        if (!str_starts_with($install->source, 'https://')) {
            return sprintf(t('mcp.install.update_archive'), $name);
        }
        try {
            $fetched = $this->sources->fromGit($install, $install->source);
        } catch (DomainException $e) {
            return $e->getMessage();
        }
        $this->installs->setLatest($name, $fetched->revision);
        if ($fetched->revision === $install->revision) {
            return sprintf(t('mcp.install.up_to_date'), $name, $install->revision);
        }
        $failed = $this->runSteps($install, $fetched->revision, $install->steps);
        if ($failed !== null) {
            return $failed;
        }
        try {
            $trial = McpEntry::fromArray($name, self::moved($install->entry, $install->path() . '/current', $install->path() . '/releases/' . $fetched->revision));
        } catch (InvalidArgumentException $e) {
            return $e->getMessage();
        }
        $rendered = ($this->render)($trial, 'member:' . $install->installedBy);
        if ($rendered->missing !== []) {
            return sprintf(t('mcp.install.secrets_missing'), implode(', ', $rendered->missing), 'member:' . $install->installedBy);
        }
        $tools = $this->tryStart($install, $rendered);
        if (is_string($tools)) {
            return $tools;
        }
        if ($install->toolsHash !== null && hash_equals($install->toolsHash, self::setHash($tools))) {
            $switched = $this->switchTo($install, $fetched->revision);
            if ($switched !== null) {
                return $switched;
            }
            $this->installs->setRevision($name, $fetched->revision, $install->toolsHash, $install->classes, $install->entry);
            return sprintf(t('mcp.install.updated'), $name, $install->revision, $fetched->revision);
        }
        /** @var array<string, mixed> $entry */
        $entry = $install->entry;
        $this->pending[$name] = ['context' => 'member:' . $install->installedBy, 'revision' => $fetched->revision, 'entry' => $entry, 'tools' => $tools, 'by' => $who->id, 'steps' => $install->steps, 'update' => true];
        return t('mcp.install.update_changed') . "\n" . $this->toApprove($name, $fetched->revision, $tools);
    }

    /** Back to the revision before: its folder stays next to the current one. */
    public function rollback(Member $who, string $name): string {
        $install = $this->installs->find($name);
        if ($install === null || $install->previous === null || $install->entry === null || $install->toolsHash === null) {
            return sprintf(t('mcp.install.no_previous'), $name);
        }
        if (!$this->mayChange($who, $install)) {
            return sprintf(t('mcp.install.not_yours'), $name);
        }
        $switched = $this->switchTo($install, $install->previous);
        if ($switched !== null) {
            return $switched;
        }
        $this->installs->setRevision($name, $install->previous, $install->toolsHash, $install->classes, $install->entry);
        return sprintf(t('mcp.install.rolled_back'), $name, $install->previous);
    }

    /** The code, the link and the record go; entries that still name it fail with their reason. */
    public function remove(Member $who, string $name): string {
        if (in_array($name, $this->bundled, true)) {
            return sprintf(t('mcp.install.bundled'), $name);
        }
        $install = $this->installs->find($name);
        if ($install === null) {
            return sprintf(t('mcp.install.not_installed'), $name);
        }
        if (!$this->mayChange($who, $install)) {
            return sprintf(t('mcp.install.not_yours'), $name);
        }
        try {
            $this->sandbox->exec(0, McpSources::limits(), 'mcp-install', 'rm -f ' . self::quote('mcp/' . $name), '.', 30, new NullCancellation());
            $this->sandbox->exec($install->holder(), McpSources::limits(), 'mcp-install', 'rm -rf releases current', '.', 120, new NullCancellation());
        } catch (SandboxFailure $e) {
            return $e->getMessage();
        }
        $this->installs->remove($name);
        unset($this->pending[$name]);
        return sprintf(t('mcp.install.removed'), $name);
    }

    /**
     * The daily look at the sources: a new revision is marked and said once; nothing updates by itself.
     *
     * @return list<array{int, string, string}> installer, name, the new revision
     */
    public function checkUpdates(): array {
        $news = [];
        foreach ($this->installs->all() as $install) {
            if ($install->revision === null || !str_starts_with($install->source, 'https://') || in_array($install->name, $this->bundled, true)) {
                continue;
            }
            try {
                $outcome = $this->sandbox->exec($install->holder(), McpSources::limits(), 'mcp-install', 'git ls-remote -- ' . self::quote($install->source) . ' HEAD', '.', 60, new NullCancellation());
            } catch (SandboxFailure) {
                continue;
            }
            if ($outcome->exitCode !== 0 || preg_match('~^([0-9a-f]{12})[0-9a-f]*\s+HEAD~m', $outcome->stdout, $m) !== 1) {
                continue;
            }
            if ($m[1] === $install->revision || $m[1] === $install->latest) {
                continue;
            }
            $this->installs->setLatest($install->name, $m[1]);
            $news[] = [$install->installedBy, $install->name, $m[1]];
        }
        return $news;
    }

    /**
     * Allows an installed MCP to another context, or takes it back.
     */
    public function allow(Member $who, string $name, string $context, bool $allowed): string {
        $install = $this->installs->find($name);
        if ($install === null) {
            return sprintf(t('mcp.install.not_installed'), $name);
        }
        if (!$this->mayChange($who, $install)) {
            return sprintf(t('mcp.install.not_yours'), $name);
        }
        if (preg_match('~^(owners|member:\d+|project:\d+)$~', $context) !== 1) {
            return t('mcp.install.bad_context');
        }
        $this->installs->allow($name, $context, $allowed);
        return sprintf(t($allowed ? 'mcp.install.allowed' : 'mcp.install.disallowed'), $name, $context);
    }

    /** The installs with their revisions, for the list. */
    public function list(): string {
        $lines = [];
        foreach ($this->installs->all() as $install) {
            $contexts = $this->installs->contexts($install->name);
            $lines[] = sprintf(t('mcp.install.line'), $install->name, $install->revision ?? '—', $install->source, $install->installedBy)
                . ($install->latest !== null && $install->latest !== $install->revision ? sprintf(t('mcp.install.line_update'), $install->latest) : '')
                . ($contexts === [] ? '' : sprintf(t('mcp.install.line_allowed'), implode(', ', $contexts)));
        }
        return $lines === [] ? t('mcp.install.none') : implode("\n", $lines);
    }

    /**
     * Whether an entry may run an install in a context: its installer's own, the shared one, or one it was allowed to.
     */
    public function allowedIn(string $name, string $context): bool {
        $install = $this->installs->find($name);
        return $install === null || $context === 'owners' || $context === 'member:' . $install->installedBy || in_array($context, $this->installs->contexts($name), true);
    }

    /**
     * @param list<string> $steps
     * @return string|null why the steps failed; null — they passed
     */
    private function runSteps(McpInstall $install, string $revision, array $steps): ?string {
        try {
            $outcome = $this->sandbox->exec(
                $install->holder(),
                McpSources::limits(),
                'mcp-install',
                "set -e\n" . implode("\n", $steps) . "\ncd .. && chmod 755 . && chmod -R o+rX " . self::quote($revision),
                'releases/' . $revision,
                self::STEPS_SECONDS,
                new NullCancellation(),
            );
        } catch (SandboxFailure $e) {
            return sprintf(t('mcp.install.steps_failed'), $e->getMessage());
        }
        if ($outcome->exitCode !== 0 || $outcome->timedOut) {
            return sprintf(t('mcp.install.steps_failed'), SecretMasker::mask(trim(mb_substr($outcome->stderr . "\n" . $outcome->stdout, -1500))));
        }
        return null;
    }

    /**
     * @return list<array{name: string, description: string, schema: array<array-key, mixed>, annotations: array<array-key, mixed>}>|string
     *         the tools, or why the start failed
     */
    private function tryStart(McpInstall $install, McpRendered $rendered): array|string {
        $server = (new McpServer(0, $install->name, 'stdio:', null, McpServer::ACTIVE, null, context: 'install'))
            ->withEndpoint($rendered->address, [], $rendered->env, hash('sha256', $rendered->address));
        try {
            return ($this->trial)($install->holder(), $server);
        } catch (RuntimeException | DomainException $e) {
            return sprintf(t('mcp.install.trial_failed'), McpManager::hide($e->getMessage(), $rendered->values));
        }
    }

    /** @param list<array{name: string, description: string, schema: array<array-key, mixed>, annotations: array<array-key, mixed>}> $tools */
    private function toApprove(string $name, string $revision, array $tools): string {
        $lines = [];
        foreach ($tools as $tool) {
            $hint = ($tool['annotations']['readOnlyHint'] ?? null) === true ? t('mcp.install.hint_read') : (($tool['annotations']['destructiveHint'] ?? null) === false ? t('mcp.install.hint_write') : '');
            $lines[] = '• ' . $tool['name'] . $hint . ' — ' . mb_substr(McpManager::clean($tool['description']), 0, 200);
        }
        return sprintf(t('mcp.install.tried'), $name, $revision, count($tools)) . "\n" . implode("\n", $lines) . "\n\n" . sprintf(t('mcp.install.approve_how'), $name);
    }

    /** @return string|null why the switch failed */
    private function switchTo(McpInstall $install, string $revision): ?string {
        try {
            $this->sandbox->exec($install->holder(), McpSources::limits(), 'mcp-install', 'ln -sfn ' . self::quote('releases/' . $revision) . ' current', '.', 30, new NullCancellation());
        } catch (SandboxFailure $e) {
            return $e->getMessage();
        }
        return null;
    }

    /** @param list<array{name: string, description: string, schema: array<array-key, mixed>, annotations: array<array-key, mixed>}> $tools */
    private static function setHash(array $tools): string {
        $names = array_column($tools, 'name');
        sort($names);
        return hash('sha256', implode("\n", $names));
    }

    /**
     * The entry with one folder of the code put in for another.
     *
     * @param array<array-key, mixed> $entry
     * @return array<string, mixed>
     */
    private static function moved(array $entry, string $from, string $to): array {
        $back = [];
        /** @psalm-suppress MixedAssignment the entry is any JSON; McpEntry checks it */
        foreach ($entry as $key => $value) {
            if (is_string($key)) {
                /** @psalm-suppress MixedAssignment */
                $back[$key] = is_string($value) ? str_replace($from, self::DIR, $value) : (is_array($value) ? array_map(static fn(mixed $v): mixed => is_string($v) ? str_replace($from, self::DIR, $v) : $v, $value) : $value);
            }
        }
        return self::placed($back, $to);
    }

    /**
     * The approval of the tried set: the classes, the revision in use, the entry in the context's file.
     *
     * @param string $mode hints — read and write as the server marks them, the rest irreversible; irreversible — all
     */
    public function approve(Member $who, string $name, string $mode): string {
        $pending = $this->pending[$name] ?? null;
        $install = $this->installs->find($name);
        if ($pending === null || $install === null) {
            return sprintf(t('mcp.install.nothing_to_approve'), $name);
        }
        if ($pending['by'] !== $who->id && !$who->isOwner()) {
            return sprintf(t('mcp.install.not_yours'), $name);
        }
        $classes = [];
        foreach ($pending['tools'] as $tool) {
            $classes[$tool['name']] = $mode === 'hints' ? self::hinted($tool['annotations']) : 'irreversible';
        }
        try {
            $entry = McpEntry::fromArray($name, $pending['entry']);
        } catch (InvalidArgumentException $e) {
            return $e->getMessage();
        }
        $switched = $this->switchTo($install, $pending['revision']);
        if ($switched !== null) {
            return $switched;
        }
        $this->installs->setRevision($name, $pending['revision'], self::setHash($pending['tools']), $classes, $entry->toArray());
        $this->installs->setSteps($name, $pending['steps']);
        unset($this->pending[$name]);
        if ($pending['update']) {
            // the entry is the same: the connections take the new set at their next listing
            return sprintf(t('mcp.install.updated'), $name, $install->revision ?? '—', $pending['revision']);
        }
        // the context the install was asked in is allowed by this approval, as the installer's own is
        if (!$this->allowedIn($name, $pending['context'])) {
            $this->installs->allow($name, $pending['context'], true);
        }
        try {
            $answer = ($this->persist)($pending['context'], $entry, $who);
        } catch (Throwable $e) {
            return sprintf(t('mcp.install.entry_failed'), $e->getMessage());
        }
        return sprintf(t('mcp.install.installed'), $name, $pending['revision'], $pending['context']) . "\n" . $answer;
    }

    /** @param array<array-key, mixed> $annotations */
    private static function hinted(array $annotations): string {
        return match (true) {
            ($annotations['readOnlyHint'] ?? null) === true => 'read',
            ($annotations['destructiveHint'] ?? null) === false => 'write',
            default => 'irreversible',
        };
    }

    private function mayChange(Member $who, McpInstall $install): bool {
        return $install->installedBy === $who->id || $who->isOwner();
    }

    /** The malware check of the dependencies the revision declares. */
    private function osvVerdict(McpInstall $install, string $revision): ?string {
        if ($this->osv === null) {
            return null;
        }
        $read = function (string $file) use ($install, $revision): ?string {
            try {
                return $this->sandbox->fetch($install->holder(), McpSources::limits(), 'releases/' . $revision . '/' . $file, 262_144, new NullCancellation());
            } catch (SandboxFailure) {
                return null;
            }
        };
        $packages = OsvCheck::manifests($read('package.json'), $read('requirements.txt'));
        return $packages === [] ? null : ($this->osv)($packages);
    }

    /**
     * The entry with its folder put in for ${INSTALL_DIR}.
     *
     * @param array<array-key, mixed> $entry
     * @return array<string, mixed>
     */
    private static function placed(array $entry, string $dir): array {
        $put = static fn(mixed $v): mixed => is_string($v) ? str_replace(self::DIR, $dir, $v) : $v;
        $placed = [];
        /** @psalm-suppress MixedAssignment the entry is any JSON; McpEntry checks it */
        foreach (['command', 'args', 'env'] as $key) {
            if (!array_key_exists($key, $entry)) {
                continue;
            }
            /** @psalm-suppress MixedAssignment the entry is any JSON; McpEntry checks it */
            $value = $entry[$key];
            $placed[$key] = is_array($value) ? array_map($put, $value) : $put($value);
        }
        return $placed;
    }

    private static function quote(string $value): string {
        return "'" . str_replace("'", "'\\''", $value) . "'";
    }
}
