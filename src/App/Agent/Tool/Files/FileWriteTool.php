<?php

declare(strict_types=1);

namespace OpenCCK\App\Agent\Tool\Files;

use Mercurius\Llm\ToolDefinition;
use OpenCCK\App\Agent\Tool\Args;
use OpenCCK\App\Agent\Tool\GrantKeyed;
use OpenCCK\App\Agent\Tool\Tool;
use OpenCCK\App\Agent\Tool\ToolContext;
use OpenCCK\App\Agent\Tool\ToolResult;
use OpenCCK\App\Server\ServerConsole;
use OpenCCK\CommandPolicy\Classification;
use OpenCCK\CommandPolicy\CommandClassifier;
use OpenCCK\Domain\Agent\DangerClass;

use function Mercurius\I18n\t;

/**
 * Writes a file on a server: the content goes on stdin of `cat`, no SFTP is needed. Every write waits for the owner;
 * the places of the "never" list (authorized_keys, cron, sudoers, systemd) are written only when the owner allowed this
 * very call, with a warning on the card. A grant names the server and the path. Reading a file is ssh_run with cat.
 *
 * spec: BRIEF.md#фаза-7-по-частям — files through cat and stdin/stdout
 * ref: references/amphp-ssh/examples/stdin-piping.php@9d171ac:L24-L34 — cat > file, pipe, end()
 */
final class FileWriteTool implements Tool, GrantKeyed {
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

    public function grantKey(array $args): ?string {
        $server = mb_strtolower(trim(Args::string($args, 'server') ?? ''));
        $path = Args::string($args, 'path') ?? '';
        if ($server === '' || $path === '') {
            return null;
        }
        // spec: BRIEF.md#решения-по-доработкам-2026-11-18 — «always» is for this content written this way at this place:
        //       a saved grant does not let a page steer another content into the same file
        return sprintf(
            '%s:%s:%s:%s:%s',
            $this->never($args) === null ? 'file-write' : 'file-write-never',
            $server,
            $path,
            ($args['append'] ?? false) === true ? 'append' : 'write',
            substr(hash('sha256', Args::string($args, 'content') ?? ''), 0, 16),
        );
    }

    public function describe(array $args): string {
        $never = $this->never($args);
        return ($never === null ? '' : sprintf(t('tool.ssh_run.never_warning'), $never) . "
")
            . sprintf(t('tool.file_write.describe'), Args::string($args, 'server') ?? '', mb_substr(Args::string($args, 'path') ?? '', 0, 300), strlen(Args::string($args, 'content') ?? ''));
    }

    /** @param array<string, mixed> $args why the place is on the "never" list; null — it is not */
    private function never(array $args): ?string {
        $class = $this->classifier->classify(self::command(Args::string($args, 'path') ?? '', ($args['append'] ?? false) === true));
        return $class->class === Classification::NEVER ? $class->reason : null;
    }

    private static function command(string $path, bool $append): string {
        return sprintf('cat %s %s', $append ? '>>' : '>', self::quote($path));
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
        $command = self::command($path, $append);
        $class = $this->classifier->classify($command);
        if ($class->class === Classification::NEVER && !$context->approved) {
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
