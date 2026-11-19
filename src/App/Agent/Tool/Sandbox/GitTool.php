<?php

declare(strict_types=1);

namespace OpenCCK\App\Agent\Tool\Sandbox;

use Mercurius\Llm\ToolDefinition;
use OpenCCK\App\Agent\Sandbox\Sandbox;
use OpenCCK\App\Agent\Sandbox\SandboxEnv;
use OpenCCK\App\Agent\Tool\Args;
use OpenCCK\App\Agent\Tool\GrantKeyed;
use OpenCCK\App\Agent\Tool\Guard\CommandClassifier;
use OpenCCK\App\Agent\Tool\ToolContext;
use OpenCCK\App\Agent\Tool\ToolResult;
use OpenCCK\App\Vault\Vault;
use OpenCCK\Domain\Agent\DangerClass;
use OpenCCK\Domain\Agent\SandboxLimits;

use function Mercurius\I18n\t;

/**
 * git in the participant's sandbox home, with a token of the vault for one origin. The token goes into the environment
 * of this one command as an HTTP header of the origin (`GIT_CONFIG_*` → `http.<origin>/.extraheader`): never into the
 * URL, the arguments other users see or the repository config, and it is masked in what git prints. The agent could
 * still read it with a program git starts, so options that start programs are refused; the rest is the owner's trust
 * (owner's decision 2026-11-18).
 *
 * spec: BRIEF.md#планировщик-и-проекты — git access from the vault: a token in the environment of one git command
 * ref: references/hermes-agent/hermes_cli/git_credentials.py@e96221fa:L137-L165 (with_git_auth) — basic auth as a
 *      one-shot extraheader of the origin in GIT_CONFIG_*; here the token is the owner's secret, not a found login
 * ref: references/hermes-agent/hermes_cli/_subprocess_compat.py@e96221fa:L290-L300 — no prompts; we keep the
 *      repository's hooks (the participant's own) and refuse only options that start other programs
 *
 * With a secret the call is never a plain read or write: the owner sees the secret and the address on a card, and a
 * grant names that pair — a page the agent read must not be able to send a token to a host of its choice. Only the
 * network commands run with it, and without the settings and hooks of the participant: a program git starts would
 * see the header in its environment. Hermes adds the header and nothing more.
 * spec: BRIEF.md#решения-по-доработкам-2026-11-18 — a call with a secret is always a card with the pair secret + address
 */
final class GitTool extends SandboxTool implements GrantKeyed {
    public const NAME = 'git';
    /** reads of the repository: no card; ls-remote talks to a server and is not one */
    private const READS = ['status', 'log', 'diff', 'show', 'blame', 'ls-files', 'rev-parse', 'describe', 'shortlog', 'grep'];
    /** options that make a read start a program or write a file: such a read waits for a card */
    private const PROGRAM_OPTIONS = '~^(-O|--open-files-in-pager|--output|--ext-diff$|--textconv$|--extcmd|-x$)~';
    /** settings off for a read: the repository's own config could start a program with them */
    private const READ_HARDENED = ['core.fsmonitor=false', 'core.hooksPath=/dev/null', 'diff.external=', 'core.pager=cat'];
    /** settings of the repository that start a program in a read and cannot be turned off from the command line */
    private const READ_PROGRAMS = '^(filter\..*\.(clean|smudge|process)|diff\..*\.(textconv|command))$';
    /** options that make git start a program the command names */
    private const REFUSED = '~^(-c|--config-env|--exec-path|--upload-pack|--receive-pack|--exec)(=|$)|^-c.~';
    /** commands where -u is --upload-pack: a short cluster with u (-u sh, -ush, -qush) starts a program there */
    private const UPLOAD_PACK = ['clone', 'fetch', 'pull', 'ls-remote'];
    /** the only commands a secret is given to: they talk to the origin and nothing else */
    private const NETWORK = ['clone', 'fetch', 'pull', 'push', 'ls-remote'];
    /** settings the participant's config could use to start a program next to the header, or to follow it elsewhere */
    private const HARDENED = ['core.fsmonitor=false', 'core.hooksPath=/dev/null', 'credential.helper=', 'http.followRedirects=false'];
    /** secrets of the agent's own settings (`/set`): never a token for git */
    private const SETTING_PREFIX = 'setting.';

    /** @param positive-int $defaultTimeout */
    public function __construct(
        Sandbox $sandbox,
        private readonly SandboxEnv $env,
        private readonly Vault $vault,
        private readonly int $defaultTimeout,
    ) {
        parent::__construct($sandbox);
    }

    public function definition(): ToolDefinition {
        return new ToolDefinition(self::NAME, t('tool.git.about'), [
            'type' => 'object',
            'properties' => [
                'args' => ['type' => 'array', 'items' => ['type' => 'string', 'maxLength' => 2000], 'minItems' => 1, 'maxItems' => 50, 'description' => t('tool.git.args')],
                'cwd' => ['type' => 'string', 'maxLength' => 4096],
                'secret' => ['type' => 'string', 'maxLength' => 64, 'description' => t('tool.git.secret')],
                'origin' => ['type' => 'string', 'maxLength' => 500, 'description' => t('tool.git.origin')],
                'username' => ['type' => 'string', 'maxLength' => 100, 'description' => t('tool.git.username')],
                'timeout' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 600],
            ],
            'required' => ['args'],
            'additionalProperties' => false,
        ]);
    }

    public function danger(array $args): DangerClass {
        if (self::secretOf($args) !== null) {
            return DangerClass::Irreversible;
        }
        $list = self::args($args);
        if ((new CommandClassifier())->classify(self::command($list))->danger === DangerClass::Irreversible) {
            return DangerClass::Irreversible;
        }
        if (in_array($list[0] ?? '', self::READS, true)) {
            // spec: BRIEF.md#решения-по-доработкам-2026-11-18 — a read that would start a program is not a read
            return self::startsProgram($list) ? DangerClass::Irreversible : DangerClass::Read;
        }
        return DangerClass::Write;
    }

    /** @param list<string> $list */
    private static function startsProgram(array $list): bool {
        foreach ($list as $arg) {
            if (preg_match(self::PROGRAM_OPTIONS, $arg) === 1) {
                return true;
            }
        }
        return false;
    }

    public function grantKey(array $args): ?string {
        $secret = self::secretOf($args);
        if ($secret !== null) {
            $origin = self::origin(Args::string($args, 'origin') ?? '');
            return $origin === null ? null : sprintf('git-secret:%s %s', $secret, $origin);
        }
        return (new CommandClassifier())->classify(self::command(self::args($args)))->key;
    }

    public function describe(array $args): string {
        $secret = self::secretOf($args);
        $origin = self::origin(Args::string($args, 'origin') ?? '') ?? (Args::string($args, 'origin') ?? '?');
        return 'git ' . implode(' ', self::args($args)) . ($secret === null ? '' : ' (' . t('tool.git.with') . ' ' . $secret . ' → ' . $origin . ')');
    }

    public function timeout(): int {
        return 600;
    }

    protected function run(array $args, ToolContext $context, SandboxLimits $limits): ToolResult {
        $list = self::args($args);
        if ($list === []) {
            return ToolResult::error(t('tool.git.bad'));
        }
        // Any word so far may be the command (git -C dir clone …): when one of them could be, -u is refused.
        $fetches = false;
        foreach ($list as $arg) {
            $fetches = $fetches || in_array($arg, self::UPLOAD_PACK, true);
            $uploadPack = $fetches && preg_match('~^-[^-]*u~', $arg) === 1;
            if ($uploadPack || preg_match(self::REFUSED, $arg) === 1) {
                return ToolResult::error(sprintf(t('tool.git.refused'), $arg));
            }
        }
        $env = ['GIT_TERMINAL_PROMPT' => '0'];
        $hidden = [];
        $hardened = [];
        $secret = self::secretOf($args);
        if ($secret !== null) {
            if (!in_array($list[0], self::NETWORK, true)) {
                return ToolResult::error(sprintf(t('tool.git.secret_commands'), implode(', ', self::NETWORK)));
            }
            if (str_starts_with(strtolower($secret), self::SETTING_PREFIX)) {
                return ToolResult::error(sprintf(t('tool.git.no_secret'), $secret));
            }
            $origin = self::origin(Args::string($args, 'origin') ?? '');
            if ($origin === null) {
                return ToolResult::error(t('tool.git.origin_bad'));
            }
            $token = $this->vault->reveal($secret, $context->principal);
            if ($token === null || $token === '') {
                return ToolResult::error(sprintf(t('tool.git.no_secret'), $secret));
            }
            $user = preg_replace('~[\r\n:]~', '', Args::string($args, 'username') ?? 'x-access-token') ?? 'x-access-token';
            $basic = base64_encode($user . ':' . $token);
            $env += [
                'GIT_CONFIG_COUNT' => '1',
                'GIT_CONFIG_KEY_0' => 'http.' . $origin . '/.extraheader',
                'GIT_CONFIG_VALUE_0' => 'Authorization: basic ' . $basic,
                'GIT_CONFIG_NOSYSTEM' => '1',
                'GIT_CONFIG_GLOBAL' => '/dev/null',
            ];
            $hidden = [$token, $basic];
            $hardened = self::HARDENED;
        }
        $check = '';
        if ($secret === null && in_array($list[0], self::READS, true) && !self::startsProgram($list)) {
            // spec: BRIEF.md#решения-по-доработкам-2026-11-18 — a read runs without a card: the settings of the
            //       repository and of the participant that start programs are off, and filters or diff programs in
            //       the repository config stop it (they cannot be turned off from the command line)
            $env += ['GIT_CONFIG_NOSYSTEM' => '1', 'GIT_CONFIG_GLOBAL' => '/dev/null'];
            $hardened = self::READ_HARDENED;
            $check = "if git config --get-regexp '" . self::READ_PROGRAMS . "' >/dev/null 2>&1; then echo '"
                . str_replace("'", "'\\''", t('tool.git.read_programs')) . "' >&2; exit 3; fi; ";
        }
        $outcome = $this->env->execEnv(
            $context->principal->id,
            $limits,
            $context->callId,
            $check . self::command($list, $hardened),
            Args::string($args, 'cwd') ?? '.',
            max(1, Args::int($args, 'timeout') ?? $this->defaultTimeout),
            $env,
            $context->cancellation,
        );
        $hide = static fn(string $text): string => $hidden === [] ? $text : str_replace($hidden, '***', $text);
        $parts = [sprintf(t('tool.run_command.kod_vyhoda'), $outcome->exitCode)];
        if ($outcome->stdout !== '') {
            $parts[] = "stdout:\n" . rtrim($hide($outcome->stdout), "\n");
        }
        if ($outcome->stderr !== '') {
            $parts[] = "stderr:\n" . rtrim($hide($outcome->stderr), "\n");
        }
        if ($outcome->timedOut) {
            $parts[] = t('tool.run_command.komanda_ne_ulozhilas_v_otvedennoe');
        }
        return new ToolResult(implode("\n\n", $parts), $outcome->exitCode !== 0 || $outcome->timedOut);
    }

    /**
     * @param array<string, mixed> $args
     * @return list<string>
     */
    private static function args(array $args): array {
        if (!isset($args['args']) || !is_array($args['args'])) {
            return [];
        }
        return array_values(array_filter($args['args'], 'is_string'));
    }

    /**
     * @param list<string> $list
     * @param list<string> $settings set by the tool itself before the command; the model's own -c is refused
     */
    private static function command(array $list, array $settings = []): string {
        $prefix = implode('', array_map(static fn(string $setting): string => '-c ' . $setting . ' ', $settings));
        return 'git ' . $prefix . implode(' ', array_map(static fn(string $a): string => "'" . str_replace("'", "'\\''", $a) . "'", $list));
    }

    /** @param array<string, mixed> $args */
    private static function secretOf(array $args): ?string {
        $secret = trim(Args::string($args, 'secret') ?? '');
        return $secret === '' ? null : $secret;
    }

    /** https://host[:port] of an address, null for anything that is not https */
    private static function origin(string $url): ?string {
        $parts = parse_url(trim($url));
        if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || !isset($parts['host']) || $parts['host'] === '' || isset($parts['user'])) {
            return null;
        }
        return 'https://' . strtolower($parts['host']) . (isset($parts['port']) ? ':' . $parts['port'] : '');
    }
}
