<?php

declare(strict_types=1);

namespace OpenCCK\App\Agent\Tool;

use DomainException;
use Mercurius\Llm\ToolDefinition;
use OpenCCK\App\Vault\Vault;
use OpenCCK\Domain\Agent\DangerClass;
use OpenCCK\Domain\Repository\ServerRepository;
use OpenCCK\Domain\Server\Server;

/**
 * Adds a server to the registry; it waits for the owner's card. The login secret is named, never given: the owner
 * stores it with /secret.
 *
 * spec: BRIEF.md#фаза-7-по-частям — the registry of servers and credentials, secrets encrypted
 */
final class ServerAddTool implements Tool {
    public const NAME = 'server_add';

    public function __construct(
        private readonly ServerRepository $servers,
        private readonly Vault $vault,
    ) {}

    public function definition(): ToolDefinition {
        return new ToolDefinition(self::NAME, 'Добавляет сервер в реестр (ждёт подтверждения владельца). secret — имя секрета с паролем или '
            . 'закрытым ключом; сам секрет владелец кладёт командой /secret <имя> <значение>, в разговор его не пишут. '
            . 'Ключ хоста владелец подтвердит при первом подключении.', [
            'type' => 'object',
            'properties' => [
                'name' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 64],
                'host' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 253],
                'port' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 65535],
                'user' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 64],
                'secret' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 64],
                'group' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 32],
            ],
            'required' => ['name', 'host', 'user', 'secret'],
            'additionalProperties' => false,
        ]);
    }

    public function danger(array $args): DangerClass {
        return DangerClass::Irreversible;
    }

    public function untrusted(): bool {
        return false;
    }

    public function timeout(): int {
        return 10;
    }

    public function execute(array $args, ToolContext $context): ToolResult {
        $name = trim(Args::string($args, 'name') ?? '');
        $host = trim(Args::string($args, 'host') ?? '');
        $user = trim(Args::string($args, 'user') ?? '');
        if (preg_match(Server::NAME_PATTERN, $name) !== 1) {
            return ToolResult::error('Имя сервера — буквы, цифры, точка, дефис, подчёркивание.');
        }
        if (preg_match('~^[A-Za-z0-9.:\-\[\]]+$~', $host) !== 1 || preg_match('~^[A-Za-z0-9._-]+$~', $user) !== 1) {
            return ToolResult::error('Хост — имя или адрес, пользователь — латиница, цифры, точка, дефис, подчёркивание.');
        }
        $secret = $this->vault->id(Args::string($args, 'secret') ?? '');
        if ($secret === null) {
            return ToolResult::error('Такого секрета нет: владелец кладёт его командой /secret <имя> <значение>.');
        }
        try {
            $server = $this->servers->add($name, trim($host, '[]'), Args::int($args, 'port') ?? 22, $user, $secret, Args::string($args, 'group') ?? 'default');
        } catch (DomainException $e) {
            return ToolResult::error($e->getMessage());
        }
        return ToolResult::ok(sprintf('Сервер «%s» (%s@%s) добавлен. При первом подключении владелец подтвердит ключ хоста.', $server->name, $server->username, $server->address()));
    }
}
