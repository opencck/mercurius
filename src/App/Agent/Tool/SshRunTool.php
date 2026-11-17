<?php

declare(strict_types=1);

namespace OpenCCK\App\Agent\Tool;

use Mercurius\Llm\ToolDefinition;
use OpenCCK\App\Server\ServerConsole;
use OpenCCK\CommandPolicy\Classification;
use OpenCCK\Domain\Agent\DangerClass;

/**
 * A command on a server of the registry. Its class decides: a proven read runs at once, a change or a raised privilege
 * waits for the owner's card, a "never" command is refused.
 *
 * spec: BRIEF.md#фаза-7-по-частям — a read runs at once, a change after confirmation
 */
final class SshRunTool implements Tool {
    public const NAME = 'ssh_run';

    public function __construct(private readonly ServerConsole $console) {}

    public function definition(): ToolDefinition {
        return new ToolDefinition(self::NAME, 'Выполняет команду на сервере из реестра (server — имя). Читающие команды из проверенного списка '
            . '(ls, cat, df, docker ps, systemctl status, journalctl…) без конвейеров и подстановок идут сразу; всё остальное — '
            . 'после подтверждения владельца; опасные (rm -rf /, mkfs, reboot, curl | sh…) запрещены. Команда выполняется в sh с '
            . 'таймаутом на сервере; stdin закрыт; вывод — до 32 КБ, секреты в нём маскируются. Каждая команда — в журнале.', [
            'type' => 'object',
            'properties' => [
                'server' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 64],
                'command' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 5000],
                'timeout' => ['type' => 'integer', 'minimum' => 1, 'maximum' => ServerConsole::TIMEOUT_MAX],
            ],
            'required' => ['server', 'command'],
            'additionalProperties' => false,
        ]);
    }

    public function danger(array $args): DangerClass {
        $class = $this->console->classify(Args::string($args, 'command') ?? '');
        return match ($class->class) {
            Classification::READ => DangerClass::Read,
            // refused in execute, nothing to confirm
            Classification::NEVER => DangerClass::Write,
            default => DangerClass::Irreversible,
        };
    }

    public function untrusted(): bool {
        return true;
    }

    public function timeout(): int {
        return ServerConsole::TIMEOUT_MAX + 60;
    }

    public function execute(array $args, ToolContext $context): ToolResult {
        [$ok, $text] = $this->console->run(
            $context->principal,
            $context->runId,
            Args::string($args, 'server') ?? '',
            Args::string($args, 'command') ?? '',
            Args::int($args, 'timeout') ?? 60,
            $context->cancellation,
        );
        return $ok ? ToolResult::ok($text) : ToolResult::error($text);
    }
}
