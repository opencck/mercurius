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

    /** @var array<string, array{context: string, revision: string, entry: array<string, mixed>, tools: list<array{name: string, description: string, schema: array<array-key, mixed>, annotations: array<array-key, mixed>}>, by: int}> */
    private array $pending = [];

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
        $server = (new McpServer(0, $name, 'stdio:', null, McpServer::ACTIVE, null, context: 'install'))
            ->withEndpoint($rendered->address, [], $rendered->env, hash('sha256', $rendered->address));
        try {
            $tools = ($this->trial)($install->holder(), $server);
        } catch (RuntimeException | DomainException $e) {
            return sprintf(t('mcp.install.trial_failed'), McpManager::hide($e->getMessage(), $rendered->values));
        }
        $this->pending[$name] = ['context' => $context, 'revision' => $revision, 'entry' => self::placed($entry, $install->path() . '/current'), 'tools' => $tools, 'by' => $who->id];
        $lines = [];
        foreach ($tools as $tool) {
            $hint = ($tool['annotations']['readOnlyHint'] ?? null) === true ? t('mcp.install.hint_read') : (($tool['annotations']['destructiveHint'] ?? null) === false ? t('mcp.install.hint_write') : '');
            $lines[] = '• ' . $tool['name'] . $hint . ' — ' . mb_substr(McpManager::clean($tool['description']), 0, 200);
        }
        return sprintf(t('mcp.install.tried'), $name, $revision, count($tools)) . "\n" . implode("\n", $lines) . "\n\n" . sprintf(t('mcp.install.approve_how'), $name);
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
            $this->sandbox->exec($install->holder(), McpSources::limits(), 'mcp-install', 'ln -sfn ' . self::quote('releases/' . $pending['revision']) . ' current', '.', 30, new NullCancellation());
        } catch (InvalidArgumentException | SandboxFailure $e) {
            return $e->getMessage();
        }
        $names = array_column($pending['tools'], 'name');
        sort($names);
        $this->installs->setRevision($name, $pending['revision'], hash('sha256', implode("\n", $names)), $classes, $entry->toArray());
        unset($this->pending[$name]);
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
