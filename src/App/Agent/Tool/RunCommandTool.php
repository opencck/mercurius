<?php

declare(strict_types=1);

namespace OpenCCK\App\Agent\Tool;

use Mercurius\Llm\ToolDefinition;
use OpenCCK\App\Agent\Sandbox\Sandbox;
use OpenCCK\Domain\Agent\DangerClass;
use OpenCCK\Domain\Agent\SandboxLimits;

/**
 * A shell command in the participant's sandbox home. Each command is independent: no shell state between calls,
 * state lives in files. Commands that act outside (git push, ssh, uploads) become irreversible with approvals.
 *
 * spec: BRIEF.md#инструменты — run_command
 * spec: BRIEF.md#песочница — each command independent, cwd as an argument
 */
final class RunCommandTool extends SandboxTool {
    public const NAME = 'run_command';

    /** @param positive-int $defaultTimeout seconds when the model gives none (AGENT_TOOL_TIMEOUT) */
    public function __construct(Sandbox $sandbox, private readonly int $defaultTimeout) {
        parent::__construct($sandbox);
    }

    public function definition(): ToolDefinition {
        return new ToolDefinition(self::NAME, 'Выполняет команду bash в твоём рабочем каталоге песочницы (есть git, curl, '
            . 'php с composer, python3, node с npm, go). Каждая команда независима: переменные и cd между вызовами не '
            . 'сохраняются. Вывод — код выхода, stdout и stderr (длинный вывод сокращается).', [
            'type' => 'object',
            'properties' => [
                'command' => ['type' => 'string', 'maxLength' => 10000],
                'cwd' => ['type' => 'string', 'maxLength' => 4096, 'description' => 'Каталог, по умолчанию рабочий'],
                'timeout' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 600, 'description' => 'Секунды'],
            ],
            'required' => ['command'],
            'additionalProperties' => false,
        ]);
    }

    public function danger(array $args): DangerClass {
        return DangerClass::Write;
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
        $parts = [sprintf('Код выхода: %d', $outcome->exitCode)];
        if ($outcome->stdout !== '') {
            $parts[] = "stdout:\n" . rtrim($outcome->stdout, "\n");
        }
        if ($outcome->stderr !== '') {
            $parts[] = "stderr:\n" . rtrim($outcome->stderr, "\n");
        }
        if ($outcome->timedOut) {
            $parts[] = 'Команда не уложилась в отведённое время и остановлена.';
        }
        if ($outcome->overQuota) {
            $parts[] = 'Рабочий каталог превысил лимит — освободи место.';
        }
        return new ToolResult(implode("\n\n", $parts), $outcome->exitCode !== 0 || $outcome->timedOut);
    }
}
