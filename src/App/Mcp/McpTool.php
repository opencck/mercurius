<?php

declare(strict_types=1);

namespace OpenCCK\App\Mcp;

use Mercurius\Mcp\McpClient;
use Closure;
use Mercurius\Llm\ToolDefinition;
use OpenCCK\App\Agent\Tool\Guard\SecretMasker;
use OpenCCK\App\Agent\Tool\Tool;
use OpenCCK\App\Agent\Tool\ToolContext;
use OpenCCK\App\Agent\Tool\ToolResult;
use OpenCCK\Domain\Agent\DangerClass;
use OpenCCK\Domain\Mcp\McpServer;
use RuntimeException;

use function Mercurius\I18n\t;

/**
 * A tool of an external MCP server in the agent's registry: the same loop, limits and confirmations as a built-in one.
 * Its class comes from the server's annotations: read-only — a read, not destructive — a write, nothing said — an
 * irreversible call. The result is untrusted data, cleaned of invisible characters.
 *
 * spec: brief/skills-mcp.md#mcp-серверы-подключение-и-доверие — a tool with no declared danger is irreversible; MCP results are untrusted
 */
final class McpTool implements Tool, \OpenCCK\App\Agent\Tool\ReadsOutside, \OpenCCK\App\Agent\Tool\GrantKeyed {
    /**
     * @param array<array-key, mixed> $schema
     * @param array<array-key, mixed> $annotations
     * @param Closure(): ?string $token the server's bearer token, from the vault, at call time
     */
    public function __construct(
        private readonly McpServer $server,
        private readonly string $remoteName,
        private readonly string $name,
        private readonly string $description,
        private readonly array $schema,
        private readonly array $annotations,
        private readonly McpClient $client,
        private readonly Closure $token,
        private readonly McpBreaker $breaker = new McpBreaker(),
    ) {}

    /** files of one call each way, as many as a letter takes */
    public const FILES_MAX = 10;

    private ?\OpenCCK\App\Agent\Sandbox\Sandbox $sandbox = null;
    private ?\DateTimeZone $zone = null;
    /** @var (Closure(): \DateTimeImmutable)|null */
    private ?Closure $now = null;

    /**
     * The sandbox of the participants: a file the server returns is kept in inbox/, a path in files goes as content.
     *
     * spec: brief/skills-mcp.md#почта-встроенный-mcp-сервер — an attachment of a letter in the sandbox, files of a letter from it
     *
     * @param (Closure(): \DateTimeImmutable)|null $now
     */
    public function useSandbox(\OpenCCK\App\Agent\Sandbox\Sandbox $sandbox, \DateTimeZone $zone, ?Closure $now = null): void {
        $this->sandbox = $sandbox;
        $this->zone = $zone;
        $this->now = $now ?? static fn(): \DateTimeImmutable => new \DateTimeImmutable();
    }

    public function definition(): ToolDefinition {
        return new ToolDefinition($this->name, sprintf('[MCP %s] %s', $this->server->name, $this->description), $this->takesFiles() ? self::withPaths($this->schema) : $this->schema);
    }

    /** The server takes files as {name, content_base64}: the agent names paths in its sandbox instead. */
    private function takesFiles(): bool {
        /** @var mixed $properties */
        $properties = $this->schema['properties'] ?? null;
        /** @var mixed $files */
        $files = is_array($properties) ? ($properties['files'] ?? null) : null;
        /** @var mixed $items */
        $items = is_array($files) ? ($files['items'] ?? null) : null;
        /** @var mixed $fields */
        $fields = is_array($items) ? ($items['properties'] ?? null) : null;
        return is_array($fields) && isset($fields['content_base64']);
    }

    /**
     * @param array<array-key, mixed> $schema
     * @return array<array-key, mixed>
     */
    private static function withPaths(array $schema): array {
        /** @var array<array-key, mixed> $properties */
        $properties = isset($schema['properties']) && is_array($schema['properties']) ? $schema['properties'] : [];
        $properties['files'] = ['type' => 'array', 'items' => ['type' => 'string'], 'maxItems' => self::FILES_MAX, 'description' => t('mcp.files_paths')];
        $schema['properties'] = $properties;
        return $schema;
    }

    public function danger(array $args): DangerClass {
        if (($this->annotations['readOnlyHint'] ?? null) === true) {
            return DangerClass::Read;
        }
        if (($this->annotations['destructiveHint'] ?? null) === false) {
            return DangerClass::Write;
        }
        return DangerClass::Irreversible;
    }

    /**
     * The files the agent named, read from its sandbox; a string — why not.
     *
     * @return list<array{name: string, mime_type: string, content_base64: string}>|string
     */
    private function outgoing(mixed $paths, ToolContext $context): array|string {
        $limits = $context->policy?->sandbox;
        if ($this->sandbox === null || $limits === null) {
            return t('mcp.files_no_sandbox');
        }
        $files = [];
        $total = 0;
        /** @var mixed $path */
        foreach (is_array($paths) ? array_slice($paths, 0, self::FILES_MAX) : [] as $path) {
            if (!is_string($path) || trim($path) === '') {
                continue;
            }
            try {
                $bytes = $this->sandbox->fetch($context->principal->id, $limits, trim($path), \OpenCCK\App\Agent\Media\AttachmentIntake::MAX_FILE, $context->cancellation);
            } catch (\OpenCCK\App\Agent\Sandbox\SandboxFailure $e) {
                return sprintf(t('mcp.files_unread'), trim($path), $e->getMessage());
            }
            $total += strlen($bytes);
            if ($total > \OpenCCK\App\Agent\Media\AttachmentIntake::MAX_TOTAL) {
                return sprintf(t('mcp.files_too_big'), \OpenCCK\App\Agent\Media\AttachmentIntake::MAX_TOTAL / 1_000_000);
            }
            $name = basename(str_replace('\\', '/', trim($path)));
            $files[] = ['name' => $name, 'mime_type' => self::mime($name), 'content_base64' => base64_encode($bytes)];
        }
        return $files;
    }

    /**
     * Keeps the files of an answer in inbox/<day>/ of the participant, under free names; returns the lines about them.
     *
     * @param list<array{name: string, type: string, bytes: string}> $files
     */
    private function keep(array $files, ToolContext $context): string {
        $limits = $context->policy?->sandbox;
        $lines = [];
        $now = $this->now === null ? new \DateTimeImmutable() : ($this->now)();
        $folder = 'inbox/' . $now->setTimezone($this->zone ?? new \DateTimeZone('UTC'))->format('Y-m-d');
        foreach (array_slice($files, 0, self::FILES_MAX) as $file) {
            if ($this->sandbox === null || $limits === null) {
                $lines[] = sprintf(t('mcp.file_not_kept'), $file['name'], t('mcp.files_no_sandbox'));
                continue;
            }
            if (strlen($file['bytes']) > \OpenCCK\App\Agent\Media\AttachmentIntake::MAX_FILE) {
                $lines[] = sprintf(t('mcp.file_not_kept'), $file['name'], sprintf(t('mcp.files_too_big'), \OpenCCK\App\Agent\Media\AttachmentIntake::MAX_FILE / 1_000_000));
                continue;
            }
            try {
                $taken = [];
                try {
                    foreach ($this->sandbox->list($context->principal->id, $limits, $folder, 1, $context->cancellation) as $entry) {
                        $taken[basename($entry['path'])] = true;
                    }
                } catch (\OpenCCK\App\Agent\Sandbox\SandboxFailure) {
                    // the folder of the day is not there yet
                }
                $number = 1;
                while (isset($taken[\OpenCCK\App\Agent\Media\MediaNames::numbered($file['name'], $number)])) {
                    $number++;
                }
                $path = $folder . '/' . \OpenCCK\App\Agent\Media\MediaNames::numbered($file['name'], $number);
                $this->sandbox->put($context->principal->id, $limits, $path, $file['bytes'], $context->cancellation);
                $lines[] = sprintf(t('mcp.file_kept'), $file['name'], intdiv(strlen($file['bytes']) + 1023, 1024), $path);
            } catch (\OpenCCK\App\Agent\Sandbox\SandboxFailure $e) {
                $lines[] = sprintf(t('mcp.file_not_kept'), $file['name'], $e->getMessage());
            }
        }
        return implode("\n", $lines);
    }

    private static function mime(string $name): string {
        return match (strtolower(pathinfo($name, PATHINFO_EXTENSION))) {
            'pdf' => 'application/pdf',
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'txt', 'md', 'log' => 'text/plain',
            'csv' => 'text/csv',
            'html', 'htm' => 'text/html',
            'json' => 'application/json',
            'zip' => 'application/zip',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            'mp3' => 'audio/mpeg',
            'mp4' => 'video/mp4',
            default => 'application/octet-stream',
        };
    }

    /** spec: brief/agent-loop.md#подтверждения-и-запреты — mail and other services bring text written by others */
    public function readsOutside(array $args): bool {
        return true;
    }

    /**
     * spec: brief/agent-loop.md#разрешения-навсегда — every irreversible action can be allowed for good; the key names the
     * action and its target (owner's decision 2026-11-17)
     */
    /** @return non-empty-string */
    public function grantKey(array $args): string {
        return sprintf('mcp:%s:%s', $this->server->name, $this->remoteName);
    }

    public function describe(array $args): string {
        return sprintf('[MCP %s] %s', $this->server->name, $this->remoteName);
    }

    public function untrusted(): bool {
        return true;
    }

    public function timeout(): int {
        return 90;
    }

    public function execute(array $args, ToolContext $context): ToolResult {
        $refusal = $this->breaker->refusal($this->server->name);
        if ($refusal !== null) {
            return ToolResult::error($refusal);
        }
        if ($this->takesFiles() && isset($args['files'])) {
            $files = $this->outgoing($args['files'], $context);
            if (is_string($files)) {
                return ToolResult::error($files);
            }
            $args['files'] = $files;
        }
        try {
            $answer = $this->client->call($this->server, ($this->token)(), $this->remoteName, $args, $context->cancellation);
            [$text, $error] = $answer;
            $returned = $answer[2] ?? [];
        } catch (RuntimeException $e) {
            $this->breaker->failed($this->server->name, false);
            return ToolResult::error($e->getMessage());
        }
        if ($error) {
            $this->breaker->failed($this->server->name, true);
        } else {
            $this->breaker->succeeded($this->server->name);
        }
        $text = SecretMasker::mask(McpManager::clean($text));
        $kept = $returned === [] ? '' : $this->keep($returned, $context);
        $text = $kept === '' ? $text : trim($text . "\n" . $kept);
        return $error ? ToolResult::error($text === '' ? t('mcp.mcp_tool.instrument_mcp_soobschil_ob_oshibke') : $text) : ToolResult::ok($text === '' ? t('mcp.mcp_tool.pusto') : $text);
    }
}
