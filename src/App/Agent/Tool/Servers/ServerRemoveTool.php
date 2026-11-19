<?php

declare(strict_types=1);

namespace OpenCCK\App\Agent\Tool\Servers;

use Mercurius\Llm\ToolDefinition;
use OpenCCK\App\Agent\Tool\Args;
use OpenCCK\App\Agent\Tool\GrantKeyed;
use OpenCCK\App\Agent\Tool\Tool;
use OpenCCK\App\Agent\Tool\ToolContext;
use OpenCCK\App\Agent\Tool\ToolResult;
use OpenCCK\Domain\Agent\DangerClass;
use OpenCCK\Domain\Repository\ServerRepository;
use OpenCCK\Domain\Server\Server;

use function Mercurius\I18n\t;

/**
 * Removes a server from the registry after the owner's card; its secret stays in the vault. A server others reach
 * through (via:<name>) stays until their route changes.
 *
 * spec: BRIEF.md#фаза-7-по-частям — the registry of servers is changed in the dialogue
 */
final class ServerRemoveTool implements Tool, GrantKeyed {
    public const NAME = 'server_remove';

    public function __construct(private readonly ServerRepository $servers) {}

    public function definition(): ToolDefinition {
        return new ToolDefinition(self::NAME, t('tool.server_remove.about'), [
            'type' => 'object',
            'properties' => ['name' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 64]],
            'required' => ['name'],
            'additionalProperties' => false,
        ]);
    }

    public function danger(array $args): DangerClass {
        return DangerClass::Irreversible;
    }

    public function grantKey(array $args): ?string {
        $name = mb_strtolower(trim(Args::string($args, 'name') ?? ''));
        return $name === '' ? null : 'server-remove:' . $name;
    }

    public function describe(array $args): string {
        return 'server_remove ' . (Args::string($args, 'name') ?? '');
    }

    public function untrusted(): bool {
        return false;
    }

    public function timeout(): int {
        return 10;
    }

    public function execute(array $args, ToolContext $context): ToolResult {
        $name = trim(Args::string($args, 'name') ?? '');
        $outcome = self::remove($this->servers, $name);
        return $outcome === null ? ToolResult::ok(sprintf(t('tool.server_remove.done'), $name)) : ToolResult::error($outcome);
    }

    /** Removes a server; null when removed, else why not. Shared with the owner's /server - <name>. */
    public static function remove(ServerRepository $servers, string $name): ?string {
        $server = $servers->byName($name);
        if ($server === null) {
            return sprintf(t('tool.server_edit.not_found'), $name);
        }
        $through = array_map(
            static fn(Server $s): string => $s->name,
            array_filter($servers->all(), static fn(Server $s): bool => mb_strtolower($s->route ?? '') === 'via:' . mb_strtolower($server->name)),
        );
        if ($through !== []) {
            return sprintf(t('tool.server_remove.used'), $server->name, implode(', ', $through));
        }
        return $servers->remove($server->id) ? null : sprintf(t('tool.server_edit.not_found'), $name);
    }
}
