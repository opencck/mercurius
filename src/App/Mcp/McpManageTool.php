<?php

declare(strict_types=1);

namespace OpenCCK\App\Mcp;

use Mercurius\Llm\ToolDefinition;
use OpenCCK\App\Agent\Tool\Args;
use OpenCCK\App\Agent\Tool\GrantKeyed;
use OpenCCK\App\Agent\Tool\Tool;
use OpenCCK\App\Agent\Tool\ToolContext;
use OpenCCK\App\Agent\Tool\ToolResult;
use OpenCCK\Domain\Agent\DangerClass;
use OpenCCK\Domain\Entity\Permission;

use function Mercurius\I18n\t;

/**
 * mcp_manage: the installed MCP servers — the list, an update next to the revision in use, a rollback, removal, and
 * which contexts may run them. An update and a removal wait for the owner's card; an update may be allowed for good.
 *
 * spec: brief/skills-mcp.md#установка-и-обновление
 */
final class McpManageTool implements Tool, GrantKeyed {
    public const NAME = 'mcp_manage';

    public function __construct(private readonly McpInstaller $installer) {}

    /** @var (\Closure(string, string, array<array-key, mixed>, \OpenCCK\Domain\Entity\Member): array{bool, string})|null writes an entry into a context's .mcp.json */
    private ?\Closure $entries = null;

    /**
     * spec: brief/skills-mcp.md#файл-mcpjson-и-одобрение-записи — an entry is written into the file of a context and
     *       waits for its card
     *
     * @param \Closure(string, string, array<array-key, mixed>, \OpenCCK\Domain\Entity\Member): array{bool, string} $entries context, name, entry, who — whether it was written and the answer
     */
    public function useEntries(\Closure $entries): void {
        $this->entries = $entries;
    }

    public function definition(): ToolDefinition {
        return new ToolDefinition(self::NAME, t('tool.mcp_manage.about'), [
            'type' => 'object',
            'properties' => [
                'action' => ['type' => 'string', 'enum' => ['list', 'entry', 'update', 'rollback', 'rebuild', 'remove', 'allow', 'disallow']],
                'name' => ['type' => 'string', 'maxLength' => 24],
                'context' => ['type' => 'string', 'maxLength' => 40],
                'entry' => ['type' => 'object', 'description' => t('tool.mcp_manage.entry')],
            ],
            'required' => ['action'],
            'additionalProperties' => false,
        ]);
    }

    public function danger(array $args): DangerClass {
        return match (Args::string($args, 'action')) {
            'list' => DangerClass::Read,
            // an entry is approved by its own card when it is written
            'allow', 'disallow', 'rollback', 'rebuild', 'entry' => DangerClass::Write,
            default => DangerClass::Irreversible,
        };
    }

    public function grantKey(array $args): ?string {
        // a grant names the action and the install (and the context): «update for good» of one MCP reaches no other
        $action = Args::string($args, 'action') ?? '';
        return $action === 'list' ? null : trim(sprintf('mcp-%s:%s %s', $action, Args::string($args, 'name') ?? '', Args::string($args, 'context') ?? ''));
    }

    public function describe(array $args): string {
        return trim(sprintf('mcp_manage %s %s %s', Args::string($args, 'action') ?? '', Args::string($args, 'name') ?? '', Args::string($args, 'context') ?? ''));
    }

    public function untrusted(): bool {
        // an update lists the tools of a new revision: the server's words
        return true;
    }

    public function timeout(): int {
        return 900;
    }

    public function execute(array $args, ToolContext $context): ToolResult {
        $who = $context->principal;
        if (Args::string($args, 'action') === 'entry') {
            // spec: brief/skills-mcp.md#устанавливаемые-mcp — a member writes an entry of an allowed MCP with their own secrets
            $entry = isset($args['entry']) && is_array($args['entry']) ? $args['entry'] : null;
            if ($this->entries === null || $entry === null) {
                return ToolResult::error(t('tool.mcp_manage.entry_needed'));
            }
            // mine — the caller's own file, whatever project the chat is in
            $where = Args::string($args, 'context') ?? $context->policy->mcpContext ?? 'member:' . $who->id;
            $where = $where === 'mine' ? 'member:' . $who->id : $where;
            // a refusal is an error: wrapped as data, it read as a written entry
            [$written, $answer] = ($this->entries)($where, Args::string($args, 'name') ?? '', $entry, $who);
            return new ToolResult($answer, !$written);
        }
        if (!$who->role->may(Permission::Mcp)) {
            return ToolResult::error(t('tool.mcp_manage.no_right'));
        }
        $name = Args::string($args, 'name') ?? '';
        $target = Args::string($args, 'context') ?? '';
        return new ToolResult(match (Args::string($args, 'action')) {
            'update' => $this->installer->update($who, $name),
            'rollback' => $this->installer->rollback($who, $name),
            'rebuild' => $this->installer->rebuild($who, $name),
            'remove' => $this->installer->remove($who, $name),
            'allow' => $this->installer->allow($who, $name, $target, true),
            'disallow' => $this->installer->allow($who, $name, $target, false),
            default => $this->installer->list(),
        });
    }
}
