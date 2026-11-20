<?php

declare(strict_types=1);

namespace OpenCCK\App\Agent\Tool\Servers;

use DomainException;
use Mercurius\Llm\ToolDefinition;
use OpenCCK\App\Agent\Tool\Args;
use OpenCCK\App\Agent\Tool\GrantKeyed;
use OpenCCK\App\Agent\Tool\Tool;
use OpenCCK\App\Agent\Tool\ToolContext;
use OpenCCK\App\Agent\Tool\ToolResult;
use OpenCCK\App\Vault\Vault;
use OpenCCK\Domain\Agent\DangerClass;
use OpenCCK\Domain\Repository\ServerRepository;
use OpenCCK\Domain\Server\Server;
use OpenCCK\Domain\Server\ServerChanges;

use function Mercurius\I18n\t;

/**
 * Changes a server of the registry after the owner's card: the address, the login, the secret by name, the group, the
 * route, the name. A server reinstalled with a new host key is fixed here: forget_host_key, or a new address, drops
 * the trusted key and the next connection asks the owner again.
 *
 * spec: brief/servers.md#фаза-7-по-частям — the registry of servers is changed in the dialogue, no SQL
 */
final class ServerEditTool implements Tool, GrantKeyed {
    public const NAME = 'server_edit';

    public function __construct(
        private readonly ServerRepository $servers,
        private readonly Vault $vault,
    ) {}

    public function definition(): ToolDefinition {
        return new ToolDefinition(self::NAME, t('tool.server_edit.about'), [
            'type' => 'object',
            'properties' => [
                'name' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 64],
                'new_name' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 64],
                'host' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 253],
                'port' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 65535],
                'user' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 64],
                'secret' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 64],
                'group' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 32],
                'route' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 80],
                'forget_host_key' => ['type' => 'boolean'],
            ],
            'required' => ['name'],
            'additionalProperties' => false,
        ]);
    }

    public function danger(array $args): DangerClass {
        return DangerClass::Irreversible;
    }

    public function grantKey(array $args): ?string {
        $name = mb_strtolower(trim(Args::string($args, 'name') ?? ''));
        return $name === '' ? null : 'server-edit:' . $name;
    }

    public function describe(array $args): string {
        $fields = [];
        foreach (['new_name', 'host', 'port', 'user', 'secret', 'group', 'route'] as $field) {
            /** @var mixed $value */
            $value = $args[$field] ?? null;
            if (is_string($value) || is_int($value)) {
                $fields[] = $field . '=' . $value;
            }
        }
        if (($args['forget_host_key'] ?? false) === true) {
            $fields[] = 'forget_host_key';
        }
        return sprintf('server_edit %s %s', Args::string($args, 'name') ?? '', implode(' ', $fields));
    }

    public function untrusted(): bool {
        return false;
    }

    public function timeout(): int {
        return 10;
    }

    public function execute(array $args, ToolContext $context): ToolResult {
        $name = trim(Args::string($args, 'name') ?? '');
        $server = $this->servers->byName($name);
        if ($server === null) {
            return ToolResult::error(sprintf(t('tool.server_edit.not_found'), $name));
        }
        $newName = Args::string($args, 'new_name');
        $host = Args::string($args, 'host');
        $user = Args::string($args, 'user');
        $route = Args::string($args, 'route');
        $secretName = Args::string($args, 'secret');
        if ($newName !== null && preg_match(Server::NAME_PATTERN, trim($newName)) !== 1) {
            return ToolResult::error(t('tool.server_add.imya_servera_bukvy_tsifry_tochka'));
        }
        if (($host !== null && preg_match(Server::HOST_PATTERN, trim($host)) !== 1) || ($user !== null && preg_match(Server::USER_PATTERN, trim($user)) !== 1)) {
            return ToolResult::error(t('tool.server_add.host_imya_ili_adres_polzovatel'));
        }
        if ($route !== null && !Server::validRoute($route)) {
            return ToolResult::error(t('tool.server_add.route_vless_secret_imya_sekreta'));
        }
        if ($route !== null && mb_strtolower($route) === 'via:' . mb_strtolower($server->name)) {
            return ToolResult::error(t('tool.server_edit.self_route'));
        }
        $secretId = null;
        if ($secretName !== null) {
            $secretId = $this->vault->id($secretName);
            if ($secretId === null) {
                return ToolResult::error(t('tool.server_add.takogo_sekreta_net_vladelets_kladet'));
            }
        }
        $changes = new ServerChanges(
            $newName === null ? null : trim($newName),
            $host === null ? null : trim(trim($host), '[]'),
            Args::int($args, 'port'),
            $user === null ? null : trim($user),
            $secretId,
            Args::string($args, 'group'),
            $route === null ? null : ($route === 'direct' ? '' : $route),
            ($args['forget_host_key'] ?? false) === true,
        );
        if ($changes->isEmpty()) {
            return ToolResult::error(t('tool.server_edit.nothing'));
        }
        $forgets = $changes->forgetsKey($server) && ($server->hostKey !== null || $server->pendingHostKey !== null);
        try {
            $updated = $this->servers->update($server->id, $changes);
        } catch (DomainException $e) {
            return ToolResult::error($e->getMessage());
        }
        if ($updated === null) {
            return ToolResult::error(sprintf(t('tool.server_edit.not_found'), $name));
        }
        return ToolResult::ok(sprintf(t('tool.server_edit.done'), $updated->name, $updated->username, $updated->address(), $updated->group, $updated->route ?? 'direct')
            . ($forgets ? t('tool.server_edit.key_reset') : ''));
    }
}
