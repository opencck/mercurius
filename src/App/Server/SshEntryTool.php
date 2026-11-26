<?php

declare(strict_types=1);

namespace OpenCCK\App\Server;

use Mercurius\Llm\ToolDefinition;
use OpenCCK\App\Agent\Tool\Args;
use OpenCCK\App\Agent\Tool\GrantKeyed;
use OpenCCK\App\Agent\Tool\Tool;
use OpenCCK\App\Agent\Tool\ToolContext;
use OpenCCK\App\Agent\Tool\ToolResult;
use OpenCCK\App\Agent\Tool\WideGrant;
use OpenCCK\CommandPolicy\Classification;
use OpenCCK\CommandPolicy\SshCommandClassifier;
use OpenCCK\Domain\Agent\DangerClass;

use function Mercurius\I18n\t;

/**
 * A tool of an SSH entry of our MCP as the model sees it (`mcp_<entry>_run`, `_read_file`, `_write_file`,
 * `_tcp_probe`), with the wrapper of the core around it: the command policy decides the card, «never» is refused
 * without the owner, the journal keeps each call, the host key goes to the owner. The call itself goes as a command
 * through the tool `run` of the entry's process; the other tools of the process are not called around the policy.
 *
 * spec: brief/servers.md#ssh-как-предустановленный-mcp — the wrapper of the core around the tools of our MCP
 */
final class SshEntryTool implements Tool, GrantKeyed, WideGrant {
    private const FILE_MAX = 1_048_576;

    public function __construct(
        private readonly Tool $inner,
        private readonly string $remote,
        private readonly SshTarget $target,
        private readonly ServerConsole $console,
        private readonly SshCommandClassifier $classifier,
    ) {}

    public function definition(): ToolDefinition {
        return $this->inner->definition();
    }

    public function danger(array $args): DangerClass {
        return match ($this->remote) {
            'run' => $this->console->classify(Args::string($args, 'command') ?? '', $this->target->key)->isRead() ? DangerClass::Read : DangerClass::Irreversible,
            // a read of a file or a port is a read: on a server of a confirmed group it waits as any command there
            'read_file', 'tcp_probe' => $this->console->classify('uptime', $this->target->key)->isRead() ? DangerClass::Read : DangerClass::Irreversible,
            default => DangerClass::Irreversible,
        };
    }

    /**
     * spec: brief/agent-loop.md#разрешения-навсегда — a grant names the exact command (or the file and its content) on
     *       this connection; the key names the context too, so an entry of another context is apart
     */
    public function grantKey(array $args): ?string {
        $key = $this->target->key;
        if ($this->remote === 'write_file') {
            $path = Args::string($args, 'path') ?? '';
            return $path === '' ? null : sprintf('%s:%s:%s:write:%s', $this->never($args) === null ? 'file-write' : 'file-write-never', $key, $path, substr(hash('sha256', Args::string($args, 'content') ?? ''), 0, 16));
        }
        if ($this->remote !== 'run') {
            return $this->danger($args) === DangerClass::Read ? null : sprintf('ssh-read:%s:%s', $key, hash('sha256', (string) json_encode($args, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)));
        }
        $command = trim(Args::string($args, 'command') ?? '');
        if ($command === '') {
            return null;
        }
        $prefix = match ($this->console->classify($command, $key)->class) {
            Classification::CHANGE => 'ssh',
            Classification::NEVER => 'ssh-never',
            Classification::PRIVILEGED => 'ssh-sudo',
            default => null,
        };
        return $prefix === null ? null : sprintf('%s:v%d:%s:%s', $prefix, SshCommandClassifier::VERSION, $key, hash('sha256', $command));
    }

    /** Every changing command on this connection; «never» and raised rights stay apart. */
    public function wideGrant(array $args): ?array {
        $command = trim(Args::string($args, 'command') ?? '');
        if ($this->remote !== 'run' || $command === '' || $this->console->classify($command, $this->target->key)->class !== Classification::CHANGE) {
            return null;
        }
        return ['ssh-any:' . $this->target->key, sprintf(t('agent.grants.wide_ssh'), $this->target->name)];
    }

    public function describe(array $args): string {
        return match ($this->remote) {
            'run' => $this->describeCommand(Args::string($args, 'command') ?? ''),
            'write_file' => ($this->never($args) === null ? '' : sprintf(t('tool.ssh_run.never_warning'), (string) $this->never($args)) . "\n")
                . sprintf(t('tool.file_write.describe'), $this->target->name, mb_substr(Args::string($args, 'path') ?? '', 0, 300), strlen(Args::string($args, 'content') ?? '')),
            default => sprintf('[MCP %s] %s %s', $this->target->name, $this->remote, mb_substr((string) json_encode($args, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 0, 300)),
        };
    }

    private function describeCommand(string $command): string {
        $class = $this->console->classify($command, $this->target->key);
        $danger = $class->class === Classification::NEVER ? sprintf(t('tool.ssh_run.never_warning'), $class->reason) . "\n" : '';
        return $danger . sprintf(t('tool.ssh_run.komanda_na_servere'), $this->target->name, mb_substr($command, 0, 300));
    }

    /** @param array<string, mixed> $args why the place is on the "never" list; null — it is not */
    private function never(array $args): ?string {
        $class = $this->classifier->classify('cat > ' . SshEntries::quote(Args::string($args, 'path') ?? ''));
        return $class->class === Classification::NEVER ? $class->reason : null;
    }

    public function untrusted(): bool {
        return true;
    }

    public function timeout(): int {
        return ServerConsole::TIMEOUT_MAX + 60;
    }

    public function execute(array $args, ToolContext $context): ToolResult {
        $run = fn(string $journalled, string $class, string $command, ?string $stdin): array => $this->console->runPrepared($context->principal, $context->runId, $this->target->key, $journalled, $class, $command, $stdin, 60, $context->cancellation, $this->target->context);
        $path = Args::string($args, 'path') ?? '';
        if (in_array($this->remote, ['read_file', 'write_file'], true) && (preg_match('~[\x00-\x1F\x7F]~', $path) === 1 || preg_match('~[\p{Cf}]~u', $path) === 1 || trim($path) !== $path || $path === '')) {
            return ToolResult::error(t('tool.file_write.v_puti_upravlyayuschie_ili_nevidimye'));
        }
        [$ok, $text] = match ($this->remote) {
            'run' => $this->console->run($context->principal, $context->runId, $this->target->key, Args::string($args, 'command') ?? '', Args::int($args, 'timeout') ?? 60, $context->cancellation, $context->approved, $this->target->context, Args::string($args, 'stdin')),
            'read_file' => $run('read_file ' . $path, Classification::READ, sprintf('head -c %d -- %s', self::FILE_MAX, SshEntries::quote($path)), null),
            'write_file' => $this->write($path, Args::string($args, 'content') ?? '', $context, $run),
            'tcp_probe' => $this->probe(Args::string($args, 'host') ?? '', Args::int($args, 'port') ?? 0, $run),
            default => [false, t('mcp.mcp_tool.instrument_mcp_soobschil_ob_oshibke')],
        };
        return $ok ? ToolResult::ok($text) : ToolResult::error($text);
    }

    /**
     * @param \Closure(string, string, string, ?string): array{bool, string} $run
     * @return array{bool, string}
     */
    private function write(string $path, string $content, ToolContext $context, \Closure $run): array {
        if (strlen($content) > self::FILE_MAX) {
            return [false, t('tool.file_write.slishkom_bolshoy_fayl_do_512')];
        }
        $never = $this->never(['path' => $path]);
        if ($never !== null && !$context->approved) {
            return [false, sprintf(t('tool.file_write.syuda_pisat_nelzya_nikogda'), $never)];
        }
        return $run(sprintf(t('tool.file_write.file_bayt'), 'write', $path, strlen($content)), Classification::CHANGE, 'cat > ' . SshEntries::quote($path), $content);
    }

    /**
     * A port check from the server, without nc: bash's /dev/tcp, cut at 5 seconds.
     *
     * @param \Closure(string, string, string, ?string): array{bool, string} $run
     * @return array{bool, string}
     */
    private function probe(string $host, int $port, \Closure $run): array {
        if (preg_match('~^[A-Za-z0-9.:-]{1,253}$~', $host) !== 1 || $port < 1 || $port > 65535) {
            return [false, t('tool.tcp_probe.bad_target')];
        }
        $command = sprintf('timeout 5 bash -c %s && echo open || { echo closed; exit 1; }', SshEntries::quote(sprintf('exec 3<>/dev/tcp/%s/%d', $host, $port)));
        return $run(sprintf('tcp_probe %s:%d', $host, $port), Classification::READ, $command, null);
    }
}
