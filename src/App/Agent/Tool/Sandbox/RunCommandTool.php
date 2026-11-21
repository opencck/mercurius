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
 */
final class RunCommandTool extends SandboxTool implements GrantKeyed {
    public const NAME = 'run_command';

    /** @param positive-int $defaultTimeout seconds when the model gives none (AGENT_TOOL_TIMEOUT) */
    public function __construct(Sandbox $sandbox, private readonly int $defaultTimeout) {
        parent::__construct($sandbox);
    }

    public function definition(): ToolDefinition {
        return new ToolDefinition(self::NAME, t('tool.run_command.vypolnyaet_komandu_bash_v_tvoem')
            . t('tool.run_command.php_s_composer_python3_node')
            . t('tool.run_command.sohranyayutsya_vyvod_kod_vyhoda_stdout'), [
            'type' => 'object',
            'properties' => [
                'command' => ['type' => 'string', 'maxLength' => 10000],
                'cwd' => ['type' => 'string', 'maxLength' => 4096, 'description' => t('tool.run_command.katalog_po_umolchaniyu_rabochiy')],
                'timeout' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 600, 'description' => t('tool.run_command.sekundy')],
            ],
            'required' => ['command'],
            'additionalProperties' => false,
        ]);
    }

    public function danger(array $args): DangerClass {
        return $this->classify($args)->danger;
    }

    public function grantKey(array $args): ?string {
        $found = $this->classify($args);
        // spec: brief/agent-loop.md#разрешения-навсегда — a command that has no key of its own is granted as this exact text
        return $found->key ?? ($found->danger === \OpenCCK\Domain\Agent\DangerClass::Irreversible
            ? 'cmd:' . substr(hash('sha256', trim(Args::string($args, 'command') ?? '')), 0, 16)
            : null);
    }

    public function describe(array $args): string {
        return $this->classify($args)->label;
    }

    /** @param array<string, mixed> $args */
    private function classify(array $args): Classification {
        return (new OutwardActionClassifier())->classify(Args::string($args, 'command') ?? '');
    }

    public function timeout(): int {
        return 600;
    }

    protected function run(array $args, ToolContext $context, SandboxLimits $limits): ToolResult {
        $outcome = $this->sandbox->exec(
            $context->principal->id,
            $limits,
            $context->callId,
            Args::string($args, 'command') ?? '',
            Args::string($args, 'cwd') ?? '.',
            max(1, Args::int($args, 'timeout') ?? $this->defaultTimeout),
            $context->cancellation,
        );
        $parts = [sprintf(t('tool.run_command.kod_vyhoda'), $outcome->exitCode)];
        if ($outcome->stdout !== '') {
            $parts[] = "stdout:\n" . rtrim($outcome->stdout, "\n");
        }
        if ($outcome->stderr !== '') {
            $parts[] = "stderr:\n" . rtrim($outcome->stderr, "\n");
        }
        if ($outcome->timedOut) {
            $parts[] = t('tool.run_command.komanda_ne_ulozhilas_v_otvedennoe');
        }
        if ($outcome->overQuota) {
            $parts[] = t('tool.run_command.rabochiy_katalog_prevysil_limit_osvobodi');
        }
        return new ToolResult(implode("\n\n", $parts), $outcome->exitCode !== 0 || $outcome->timedOut);
    }
}
