<?php

declare(strict_types=1);

namespace OpenCCK\App\Agent\Tool\Servers;

use Mercurius\Llm\ToolDefinition;
use OpenCCK\App\Agent\Tool\Tool;
use OpenCCK\App\Agent\Tool\ToolContext;
use OpenCCK\App\Agent\Tool\ToolResult;
use OpenCCK\Domain\Agent\DangerClass;
use OpenCCK\Domain\Repository\ServerRepository;
use OpenCCK\Domain\Server\Server;

use function Mercurius\I18n\t;

/**
 * The registry of servers: names, addresses, logins and whether the host key is trusted; never a secret.
 *
 * spec: BRIEF.md#фаза-7-по-частям
 */
final class ServersTool implements Tool {
    public const NAME = 'servers';

    public function __construct(private readonly ServerRepository $servers) {}

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
        $all = $this->servers->all();
        if ($all === []) {
            return ToolResult::ok(t('tool.servers.serverov_v_reestre_net_dobavit'));
        }
        return ToolResult::ok(implode("\n", array_map(static fn(Server $s): string => sprintf(
            t('tool.servers.line'),
            $s->name,
            $s->username,
            $s->address(),
            $s->group,
            $s->route ?? t('tool.servers.napryamuyu'),
            $s->hostKey === null ? ($s->pendingHostKey === null ? t('tool.servers.esche_ne_videli') : t('tool.servers.zhdet_podtverzhdeniya_vladeltsa')) : t('tool.servers.podtverzhden'),
            $s->secretId === null ? t('tool.servers.sekreta_dlya_vhoda_net') : '',
        ), $all)));
    }
}
