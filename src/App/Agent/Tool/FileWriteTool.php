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
 * Writes a file on a server: the content goes on stdin of `cat`, no SFTP is needed. Every write waits for the owner;
 * the places of the "never" list (authorized_keys, cron, sudoers, systemd) are refused. Reading a file is ssh_run with
 * cat.
 *
 * spec: BRIEF.md#фаза-7-по-частям — files through cat and stdin/stdout
 * ref: references/amphp-ssh/examples/stdin-piping.php@9d171ac:L24-L34 — cat > file, pipe, end()
 */
final class FileWriteTool implements Tool {
    public const NAME = 'file_write';
    public const MAX_BYTES = 524_288;

    public function __construct(
        private readonly ServerConsole $console,
        private readonly CommandClassifier $classifier,
    ) {}

    public function definition(): ToolDefinition {
        return new ToolDefinition(self::NAME, t('tool.file_write.zapisyvaet_fayl_na_servere_iz')
            . t('tool.file_write.do_512_kb_teksta_append'), [
            'type' => 'object',
            'properties' => [
                'server' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 64],
                'path' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 1024],
                'content' => ['type' => 'string', 'maxLength' => self::MAX_BYTES],
                'append' => ['type' => 'boolean'],
            ],
            'required' => ['server', 'path', 'content'],
            'additionalProperties' => false,
        ]);
    }

    public function danger(array $args): DangerClass {
        return DangerClass::Irreversible;
    }

    public function untrusted(): bool {
        return true;
    }

    public function timeout(): int {
        return 120;
    }

    public function execute(array $args, ToolContext $context): ToolResult {
        $path = Args::string($args, 'path') ?? '';
        $content = Args::string($args, 'content') ?? '';
        if (preg_match('~[\x00-\x1F\x7F]~', $path) === 1 || preg_match('~[\p{Cf}]~u', $path) === 1 || trim($path) !== $path) {
            return ToolResult::error(t('tool.file_write.v_puti_upravlyayuschie_ili_nevidimye'));
        }
        if (strlen($content) > self::MAX_BYTES) {
            return ToolResult::error(t('tool.file_write.slishkom_bolshoy_fayl_do_512'));
        }
        $append = ($args['append'] ?? false) === true;
        $command = sprintf('cat %s %s', $append ? '>>' : '>', self::quote($path));
        $class = $this->classifier->classify($command);
        if ($class->class === Classification::NEVER) {
            return ToolResult::error(sprintf(t('tool.file_write.syuda_pisat_nelzya_nikogda'), $class->reason));
        }
        [$ok, $text] = $this->console->runPrepared(
            $context->principal,
            $context->runId,
            Args::string($args, 'server') ?? '',
            sprintf(t('tool.file_write.file_bayt'), $append ? 'append' : 'write', $path, strlen($content)),
            Classification::CHANGE,
            $command,
            $content,
            60,
            $context->cancellation,
        );
        return $ok ? ToolResult::ok(sprintf(t('tool.file_write.zapisano_bayt_v'), strlen($content), $path)) : ToolResult::error($text);
    }

    private static function quote(string $text): string {
        return "'" . str_replace("'", "'\\''", $text) . "'";
    }
}
