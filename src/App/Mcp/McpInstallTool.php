<?php

declare(strict_types=1);

namespace OpenCCK\App\Mcp;

use Amp\NullCancellation;
use Mercurius\Llm\ToolDefinition;
use OpenCCK\App\Agent\Sandbox\Sandbox;
use OpenCCK\App\Agent\Sandbox\SandboxFailure;
use OpenCCK\App\Agent\Tool\Args;
use OpenCCK\App\Agent\Tool\GrantKeyed;
use OpenCCK\App\Agent\Tool\Tool;
use OpenCCK\App\Agent\Tool\ToolContext;
use OpenCCK\App\Agent\Tool\ToolResult;
use OpenCCK\Domain\Agent\DangerClass;
use OpenCCK\Domain\Entity\Permission;

use function Mercurius\I18n\t;

/**
 * mcp_install: fetch — the sources of an MCP into the sandbox for the agent to read and plan by; install — the plan the
 * agent made (steps and the entry of .mcp.json), run by the install's own user, and a trial start; approve — the
 * tried set of tools with its danger classes, the revision pinned and the entry written. Each step that runs code or
 * changes what the agent can do waits for the card of the owner.
 *
 * spec: brief/skills-mcp.md#установка-и-обновление
 */
final class McpInstallTool implements Tool, GrantKeyed {
    public const NAME = 'mcp_install';
    /** an archive from the chat is at most this */
    private const ARCHIVE_MAX = 20_971_520;

    public function __construct(
        private readonly McpInstaller $installer,
        private readonly Sandbox $sandbox,
    ) {}

    public function definition(): ToolDefinition {
        return new ToolDefinition(self::NAME, t('tool.mcp_install.about'), [
            'type' => 'object',
            'properties' => [
                'action' => ['type' => 'string', 'enum' => ['fetch', 'install', 'approve']],
                'name' => ['type' => 'string', 'maxLength' => 24, 'pattern' => '^[a-z0-9][a-z0-9_]{0,23}$', 'description' => t('tool.mcp_install.name')],
                'url' => ['type' => 'string', 'maxLength' => 500, 'description' => t('tool.mcp_install.url')],
                'path' => ['type' => 'string', 'maxLength' => 4096, 'description' => t('tool.mcp_install.path')],
                'steps' => ['type' => 'array', 'items' => ['type' => 'string', 'maxLength' => 1000], 'maxItems' => 12, 'description' => t('tool.mcp_install.steps')],
                'entry' => [
                    'type' => 'object',
                    'description' => t('tool.mcp_install.entry'),
                    'properties' => [
                        'command' => ['type' => 'string', 'maxLength' => 200],
                        'args' => ['type' => 'array', 'items' => ['type' => 'string', 'maxLength' => 2000], 'maxItems' => 50],
                        'env' => ['type' => 'object', 'additionalProperties' => ['type' => 'string', 'maxLength' => 2000]],
                    ],
                    'required' => ['command'],
                    'additionalProperties' => false,
                ],
                'classes' => ['type' => 'string', 'enum' => ['hints', 'irreversible'], 'description' => t('tool.mcp_install.classes')],
            ],
            'required' => ['action', 'name'],
            'additionalProperties' => false,
        ]);
    }

    public function danger(array $args): DangerClass {
        // fetching only puts files in the sandbox; installing runs code and approving gives the agent new tools
        return Args::string($args, 'action') === 'fetch' ? DangerClass::Write : DangerClass::Irreversible;
    }

    public function grantKey(array $args): string {
        // a source may be fetched again without a card once allowed; a grant of installing or approving names the exact
        // plan or set, so it never reaches another one
        $action = Args::string($args, 'action') ?? '';
        $url = Args::string($args, 'url');
        if ($action === 'fetch') {
            return 'mcp-source:' . ($url ?? Args::string($args, 'path') ?? '');
        }
        $plan = json_encode([$args['name'] ?? null, $args['steps'] ?? null, $args['entry'] ?? null, $args['classes'] ?? null], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return sprintf('mcp-%s:%s %s', $action, Args::string($args, 'name') ?? '', substr(hash('sha256', (string) $plan), 0, 16));
    }

    public function describe(array $args): string {
        $name = Args::string($args, 'name') ?? '';
        return match (Args::string($args, 'action')) {
            'fetch' => sprintf(t('mcp.install.card_fetch'), $name, Args::string($args, 'url') ?? Args::string($args, 'path') ?? '?'),
            'install' => sprintf(t('mcp.install.card_install'), $name, implode("\n", array_map(static fn(string $s): string => '• ' . $s, self::steps($args))), (string) json_encode(self::entry($args), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
            default => sprintf(t('mcp.install.card_approve'), $name, Args::string($args, 'classes') ?? 'irreversible'),
        };
    }

    public function untrusted(): bool {
        // the sources and the tools a server lists are the repository's words, not instructions
        return true;
    }

    public function timeout(): int {
        return 900;
    }

    public function execute(array $args, ToolContext $context): ToolResult {
        $who = $context->principal;
        if (!$who->role->may(Permission::Mcp)) {
            return ToolResult::error(t('tool.mcp_install.no_right'));
        }
        $name = Args::string($args, 'name') ?? '';
        // spec: brief/skills-mcp.md#установка-и-обновление — a name the install cannot take is an error, not data: as data
        //      the model repeated the same call
        if (in_array(Args::string($args, 'action'), ['fetch', 'install', 'approve'], true) && preg_match(\OpenCCK\Domain\Mcp\McpInstall::NAME_PATTERN, $name) !== 1) {
            return ToolResult::error(t('mcp.install.bad_name'));
        }
        $answer = match (Args::string($args, 'action')) {
            'fetch' => $this->fetch($name, $args, $context),
            'install' => $this->installer->install($who, $this->context($context), $name, self::steps($args), self::entry($args)),
            'approve' => $this->installer->approve($who, $name, Args::string($args, 'classes') ?? 'irreversible'),
            default => t('mcp.install.no_source'),
        };
        return new ToolResult($answer);
    }

    /** @param array<string, mixed> $args */
    private function fetch(string $name, array $args, ToolContext $context): string {
        $url = Args::string($args, 'url');
        $path = Args::string($args, 'path');
        if ($url !== null || $path === null) {
            return $this->installer->fetch($context->principal, $name, $url, null);
        }
        $limits = $context->policy?->sandbox;
        if ($limits === null) {
            return t('tool.sandbox.pesochnitsa_nedostupna_dlya_uchastnika');
        }
        try {
            $bytes = $this->sandbox->fetch($context->principal->id, $limits, $path, self::ARCHIVE_MAX, new NullCancellation());
        } catch (SandboxFailure $e) {
            return $e->getMessage();
        }
        return $this->installer->fetch($context->principal, $name, null, [basename($path), $bytes]);
    }

    /**
     * Where the entry goes: the context of the run; the shared one only for an owner of the agent.
     */
    private function context(ToolContext $context): string {
        $mcp = $context->policy?->mcpContext;
        if ($mcp === null || ($mcp === 'owners' && !$context->principal->isOwner())) {
            return 'member:' . $context->principal->id;
        }
        return $mcp;
    }

    /**
     * @param array<string, mixed> $args
     * @return list<string>
     */
    private static function steps(array $args): array {
        /** @psalm-suppress MixedAssignment the steps are any JSON; only strings are taken */
        $steps = $args['steps'] ?? [];
        return is_array($steps) ? array_values(array_filter($steps, 'is_string')) : [];
    }

    /**
     * @param array<string, mixed> $args
     * @return array<array-key, mixed>
     */
    private static function entry(array $args): array {
        /** @psalm-suppress MixedAssignment the entry is any JSON; McpEntry checks it */
        $entry = $args['entry'] ?? null;
        return is_array($entry) ? $entry : [];
    }
}
