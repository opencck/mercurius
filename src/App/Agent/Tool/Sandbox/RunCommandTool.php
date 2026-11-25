<?php

declare(strict_types=1);

namespace OpenCCK\App\Agent\Tool\Sandbox;

use Mercurius\Llm\ToolDefinition;
use OpenCCK\App\Agent\Sandbox\Sandbox;
use OpenCCK\App\Agent\Tool\Args;
use OpenCCK\App\Agent\Tool\GrantKeyed;
use OpenCCK\App\Agent\Tool\Guard\Classification;
use OpenCCK\App\Agent\Tool\Guard\OutwardActionClassifier;
use OpenCCK\App\Agent\Tool\ToolContext;
use OpenCCK\App\Agent\Tool\ToolResult;
use OpenCCK\Domain\Agent\DangerClass;
use OpenCCK\Domain\Agent\SandboxLimits;

use function Mercurius\I18n\t;

/**
 * A shell command in the participant's sandbox home. Each command is independent: no shell state between calls,
 * state lives in files. Commands that act outside (git push, ssh, uploads) become irreversible with approvals.
 *
 * spec: brief/agent-loop.md#инструменты — run_command
 * spec: brief/agent-loop.md#песочница — each command independent, cwd as an argument
 * spec: brief/agent-loop.md#секреты-маскирование-и-вызовы-с-секретом — a secret goes into a command as a variable of
 *       its environment, never into its text; the call is a card with the secrets and the command, the output masked
 * ref: references/hermes-agent/tools/mcp_tool_config.py@e96221fa:L199-L246 (_build_safe_env) — a credential reaches a
 *      child process through its own environment only; here per call, by name, after the owner's card
 */
final class RunCommandTool extends SandboxTool implements GrantKeyed {
    public const NAME = 'run_command';

    /** @param positive-int $defaultTimeout seconds when the model gives none (AGENT_TOOL_TIMEOUT) */
    /** variables a command may take secrets in: one call, a few */
    private const SECRETS_MAX = 8;
    private const VARIABLE = '~^[A-Z_][A-Z0-9_]{0,63}$~';

    private ?\OpenCCK\App\Agent\Sandbox\SandboxEnv $env = null;
    /** @var (\Closure(string, ToolContext): ?string)|null */
    private ?\Closure $secret = null;

    /** @param positive-int $defaultTimeout seconds when the model gives none (AGENT_TOOL_TIMEOUT) */
    public function __construct(Sandbox $sandbox, private readonly int $defaultTimeout) {
        parent::__construct($sandbox);
    }

    /**
     * Secrets for commands: the value of a name for the caller, from their own secrets and the wider layers of the
     * context they inherit.
     *
     * @param \Closure(string, ToolContext): ?string $secret
     */
    public function useSecrets(\OpenCCK\App\Agent\Sandbox\SandboxEnv $env, \Closure $secret): void {
        $this->env = $env;
        $this->secret = $secret;
    }

    public function definition(): ToolDefinition {
        return new ToolDefinition(self::NAME, t('tool.run_command.vypolnyaet_komandu_bash_v_tvoem')
            . t('tool.run_command.php_s_composer_python3_node')
            . t('tool.run_command.sohranyayutsya_vyvod_kod_vyhoda_stdout'), [
            'type' => 'object',
            'properties' => [
                'area' => self::areaProperty(),
                'command' => ['type' => 'string', 'maxLength' => 10000],
                'cwd' => ['type' => 'string', 'maxLength' => 4096, 'description' => t('tool.run_command.katalog_po_umolchaniyu_rabochiy')],
                'timeout' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 600, 'description' => t('tool.run_command.sekundy')],
                'secrets' => ['type' => 'object', 'additionalProperties' => ['type' => 'string', 'maxLength' => 64], 'description' => t('tool.run_command.secrets')],
            ],
            'required' => ['command'],
            'additionalProperties' => false,
        ]);
    }

    /** @param array<string, mixed> $args */
    private function ownDanger(array $args): DangerClass {
        // a command with a secret can send it anywhere: always the owner's card
        return self::secretsOf($args) !== [] ? DangerClass::Irreversible : $this->classify($args)->danger;
    }

    /**
     * @param array<string, mixed> $args
     * @return array<string, string> variable => name of the secret
     */
    private static function secretsOf(array $args): array {
        $found = [];
        /** @psalm-suppress MixedAssignment the arguments come from the model; each value is checked below */
        $given = $args['secrets'] ?? null;
        if (is_array($given)) {
            /** @psalm-suppress MixedAssignment */
            foreach ($given as $variable => $name) {
                if (is_string($name) && $name !== '') {
                    $found[(string) $variable] = $name;
                }
            }
        }
        return $found;
    }

    /** @param array<string, mixed> $args */
    private function ownGrantKey(array $args): ?string {
        $secrets = self::secretsOf($args);
        if ($secrets !== []) {
            // spec: brief/agent-loop.md#разрешения-навсегда — a grant for these secrets in this exact command
            $names = array_values(array_unique($secrets));
            sort($names);
            return 'cmd-secret:' . implode(',', $names) . ' ' . substr(hash('sha256', trim(Args::string($args, 'command') ?? '')), 0, 16);
        }
        $found = $this->classify($args);
        // spec: brief/agent-loop.md#разрешения-навсегда — a command that has no key of its own is granted as this exact text
        return $found->key ?? ($this->danger($args) === \OpenCCK\Domain\Agent\DangerClass::Irreversible
            ? 'cmd:' . substr(hash('sha256', trim(Args::string($args, 'command') ?? '')), 0, 16)
            : null);
    }

    /** @param array<string, mixed> $args */
    private function ownDescribe(array $args): string {
        $secrets = self::secretsOf($args);
        $label = $this->classify($args)->label;
        if ($secrets === []) {
            return $label;
        }
        $pairs = [];
        foreach ($secrets as $variable => $name) {
            $pairs[] = $variable . ' ← ' . $name;
        }
        return sprintf(t('tool.run_command.with_secrets'), $label, implode(', ', $pairs));
    }

    /** @param array<string, mixed> $args */
    private function classify(array $args): Classification {
        return (new OutwardActionClassifier())->classify(Args::string($args, 'command') ?? '');
    }

    public function timeout(): int {
        return 600;
    }

    protected function run(array $args, ToolContext $context, SandboxLimits $limits): ToolResult {
        $secrets = self::secretsOf($args);
        $env = [];
        if ($secrets !== []) {
            $secret = $this->secret;
            if ($secret === null || $this->env === null) {
                return ToolResult::error(t('tool.run_command.secrets_off'));
            }
            if (count($secrets) > self::SECRETS_MAX) {
                return ToolResult::error(sprintf(t('tool.run_command.secrets_many'), self::SECRETS_MAX));
            }
            foreach ($secrets as $variable => $name) {
                if (preg_match(self::VARIABLE, $variable) !== 1) {
                    return ToolResult::error(sprintf(t('tool.run_command.secret_variable'), mb_substr($variable, 0, 64)));
                }
                $value = $secret($name, $context);
                if ($value === null || $value === '') {
                    return ToolResult::error(sprintf(t('tool.run_command.no_secret'), $name));
                }
                $env[$variable] = $value;
            }
        }
        $command = Args::string($args, 'command') ?? '';
        $cwd = Args::string($args, 'cwd') ?? '.';
        $timeout = max(1, Args::int($args, 'timeout') ?? $this->defaultTimeout);
        $outcome = $env === [] || $this->env === null
            ? $this->sandbox->exec($this->holder($args, $context), $limits, $context->callId, $command, $cwd, $timeout, $context->cancellation)
            : $this->env->execEnv($this->holder($args, $context), $limits, $context->callId, $command, $cwd, $timeout, $env, $context->cancellation);
        // the values put in are masked whatever their length: the command may print them
        $values = array_values($env);
        usort($values, static fn(string $a, string $b): int => strlen($b) <=> strlen($a));
        $hide = static fn(string $text): string => $values === [] ? $text : str_replace($values, '***', $text);
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
        if ($outcome->overQuota) {
            $parts[] = t('tool.run_command.rabochiy_katalog_prevysil_limit_osvobodi');
        }
        return new ToolResult(implode("\n\n", $parts), $outcome->exitCode !== 0 || $outcome->timedOut);
    }

    public function grantKey(array $args): ?string {
        return self::areaGrant($args, $this->ownGrantKey($args));
    }

    public function describe(array $args): string {
        return self::areaLabel($args, $this->ownDescribe($args));
    }

    public function danger(array $args): DangerClass {
        return self::areaDanger($args, $this->ownDanger($args));
    }
}
