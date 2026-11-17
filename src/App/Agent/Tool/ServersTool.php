<?php

declare(strict_types=1);

namespace OpenCCK\App\Agent\Tool;

use Mercurius\Llm\ToolDefinition;
use OpenCCK\Domain\Agent\DangerClass;
use OpenCCK\Domain\Repository\ServerRepository;
use OpenCCK\Domain\Server\Server;

/**
 * The registry of servers: names, addresses, logins and whether the host key is trusted; never a secret.
 *
 * spec: BRIEF.md#фаза-7-по-частям
 */
final class ServersTool implements Tool {
    public const NAME = 'servers';

    public function __construct(private readonly ServerRepository $servers) {}

    public function definition(): ToolDefinition {
        return new ToolDefinition(self::NAME, 'Список серверов из реестра: имя, адрес, пользователь, группа, подтверждён ли ключ хоста.');
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
            return ToolResult::ok('Серверов в реестре нет. Добавить — server_add (с подтверждением владельца).');
        }
        return ToolResult::ok(implode("\n", array_map(static fn(Server $s): string => sprintf(
            '%s — %s@%s, группа %s, маршрут %s, ключ хоста: %s%s',
            $s->name,
            $s->username,
            $s->address(),
            $s->group,
            $s->route ?? 'напрямую',
            $s->hostKey === null ? ($s->pendingHostKey === null ? 'ещё не видели' : 'ждёт подтверждения владельца') : 'подтверждён',
            $s->secretId === null ? ', секрета для входа нет' : '',
        ), $all)));
    }
}
