<?php

declare(strict_types=1);

namespace OpenCCK\App\Agent\Tool;

use Mercurius\Llm\ToolDefinition;
use OpenCCK\App\Server\ServerConsole;
use OpenCCK\CommandPolicy\Classification;
use OpenCCK\CommandPolicy\CommandClassifier;
use OpenCCK\Domain\Agent\DangerClass;

use function Mercurius\I18n\t;

/**
 * A command on a server of the registry. Its class decides: a proven read runs at once, a change or a raised privilege
 * waits for the owner's card, a "never" command is refused.
 *
 * spec: BRIEF.md#фаза-7-по-частям — a read runs at once, a change after confirmation
 */
final class SshRunTool implements Tool, GrantKeyed {
    public const NAME = 'ssh_run';

    public function __construct(private readonly ServerConsole $console) {}

    public function definition(): ToolDefinition {
        return new ToolDefinition(self::NAME, t('tool.ssh_run.vypolnyaet_komandu_na_servere_iz')
            . t('tool.ssh_run.ls_cat_df_docker_ps')
            . t('tool.ssh_run.posle_podtverzhdeniya_vladeltsa_opasnye')
            . t('tool.ssh_run.taymautom_na_servere_stdin_zakryt'), [
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
        $class = $this->console->classify(Args::string($args, 'command') ?? '', Args::string($args, 'server'));
        return match ($class->class) {
            Classification::READ => DangerClass::Read,
            default => DangerClass::Irreversible,
        };
    }

    /**
     * A change can be granted for good — this exact command on this server, under this version of the policy; raising
     * privileges is asked every time.
     *
     * spec: BRIEF.md#фаза-7-по-частям — one-off grants as the base of learned preferences; a change of the policy drops
     *       the grants
     */
    public function grantKey(array $args): ?string {
        $command = trim(Args::string($args, 'command') ?? '');
        $server = mb_strtolower(trim(Args::string($args, 'server') ?? ''));
        if ($command === '' || $server === '') {
            return null;
        }
        // spec: BRIEF.md#подтверждения-и-запреты — the owner may allow even a command of the "never" list for good; its
        //      key is apart, so the list of grants shows it
        $prefix = match ($this->console->classify($command, $server)->class) {
            Classification::CHANGE => 'ssh',
            Classification::NEVER => 'ssh-never',
            default => null,
        };
        return $prefix === null ? null : sprintf('%s:v%d:%s:%s', $prefix, CommandClassifier::VERSION, $server, hash('sha256', $command));
    }

    public function describe(array $args): string {
        $class = $this->console->classify(Args::string($args, 'command') ?? '', Args::string($args, 'server'));
        $danger = $class->class === Classification::NEVER ? sprintf(t('tool.ssh_run.never_warning'), $class->reason) . "
" : '';
        return $danger . sprintf(t('tool.ssh_run.komanda_na_servere'), Args::string($args, 'server') ?? '', mb_substr(Args::string($args, 'command') ?? '', 0, 300));
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
            $context->approved,
        );
        return $ok ? ToolResult::ok($text) : ToolResult::error($text);
    }
}
