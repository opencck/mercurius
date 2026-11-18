<?php

declare(strict_types=1);

namespace OpenCCK\App\Agent\Tool;

use Mercurius\Llm\ToolDefinition;
use OpenCCK\App\Agent\Sandbox\Sandbox;
use OpenCCK\App\Agent\Sandbox\SandboxEnv;
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
 */
final class GitTool extends SandboxTool implements GrantKeyed {
    public const NAME = 'git';
    private const READS = ['status', 'log', 'diff', 'show', 'blame', 'ls-files', 'ls-remote', 'rev-parse', 'describe', 'shortlog', 'grep'];
    /** options that make git start a program the command names */
    private const REFUSED = '~^(-c|--config-env|--exec-path|--upload-pack|--receive-pack|--exec|-u)(=|$)|^-c.~';

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
        $list = self::args($args);
        if ((new CommandClassifier())->classify(self::command($list))->danger === DangerClass::Irreversible) {
            return DangerClass::Irreversible;
        }
        return in_array($list[0] ?? '', self::READS, true) ? DangerClass::Read : DangerClass::Write;
    }

    public function grantKey(array $args): ?string {
        return (new CommandClassifier())->classify(self::command(self::args($args)))->key;
    }

    public function describe(array $args): string {
        $secret = Args::string($args, 'secret');
        return 'git ' . implode(' ', self::args($args)) . ($secret === null ? '' : ' (' . t('tool.git.with') . ' ' . $secret . ')');
    }

    public function timeout(): int {
        return 600;
    }

    protected function run(array $args, ToolContext $context, SandboxLimits $limits): ToolResult {
        $list = self::args($args);
        if ($list === []) {
            return ToolResult::error(t('tool.git.bad'));
        }
        foreach ($list as $arg) {
            if (preg_match(self::REFUSED, $arg) === 1) {
                return ToolResult::error(sprintf(t('tool.git.refused'), $arg));
            }
        }
        $env = ['GIT_TERMINAL_PROMPT' => '0'];
        $hidden = [];
        $secret = Args::string($args, 'secret');
        if ($secret !== null && $secret !== '') {
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
            ];
            $hidden = [$token, $basic];
        }
        $outcome = $this->env->execEnv(
            $context->principal->id,
            $limits,
            $context->callId,
            self::command($list),
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

    /** @param list<string> $list */
    private static function command(array $list): string {
        return 'git ' . implode(' ', array_map(static fn(string $a): string => "'" . str_replace("'", "'\\''", $a) . "'", $list));
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
