<?php

declare(strict_types=1);

namespace OpenCCK\App\Agent\Tool\Servers;

use Mercurius\Llm\ToolDefinition;
use OpenCCK\App\Agent\Tool\Tool;
use OpenCCK\App\Agent\Tool\ToolContext;
use OpenCCK\App\Agent\Tool\ToolResult;
use OpenCCK\App\Server\SshEntries;
use OpenCCK\App\Server\SshTarget;
use OpenCCK\Domain\Agent\DangerClass;
use OpenCCK\Domain\Repository\SshHostKeyRepository;

use function Mercurius\I18n\t;

/**
 * The servers a participant reaches from the run: the SSH entries of our MCP in the context of the run and their own,
 * without secrets.
 *
 * spec: brief/servers.md#ssh-как-предустановленный-mcp — a server is an entry of .mcp.json
 */
final class ServersTool implements Tool {
    public const NAME = 'servers';

    public function __construct(
        private readonly SshEntries $entries,
        private readonly SshHostKeyRepository $keys,
    ) {}

    public function definition(): ToolDefinition {
        return new ToolDefinition(self::NAME, t('tool.servers.spisok_serverov_iz_reestra_imya'));
    }

    public function danger(array $args): DangerClass {
        return DangerClass::Read;
    }

    public function untrusted(): bool {
        return false;
    }

    public function timeout(): int {
        return 10;
    }

    public function execute(array $args, ToolContext $context): ToolResult {
        $all = $this->entries->visible($context->principal->id, $context->policy?->mcpContext);
        if ($all === []) {
            return ToolResult::ok(t('tool.servers.serverov_v_reestre_net_dobavit'));
        }
        return ToolResult::ok(implode("\n", array_map(fn(SshTarget $s): string => sprintf(
            t('tool.servers.line'),
            $s->name,
            $s->user,
            $s->address(),
            $s->group,
            $s->jumpAddress() === null ? t('tool.servers.napryamuyu') : 'via ' . (string) $s->jumpUser . '@' . (string) $s->jumpAddress(),
            $this->state($s),
            '',
        ), $all)));
    }

    private function state(SshTarget $s): string {
        $row = $this->keys->of($s->key, $s->address());
        return $row?->hostKey !== null ? t('tool.servers.podtverzhden') : ($row?->pending !== null ? t('tool.servers.zhdet_podtverzhdeniya_vladeltsa') : t('tool.servers.esche_ne_videli'));
    }
}
