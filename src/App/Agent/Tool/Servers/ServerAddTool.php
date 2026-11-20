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

use function Mercurius\I18n\t;

/**
 * Adds a server to the registry; it waits for the owner's card. The login secret is named, never given: the owner
 * stores it with /secret.
 *
 * spec: brief/servers.md#как-сделано-хранилище-реестр-и-ssh — the registry of servers and credentials, secrets encrypted
 */
final class ServerAddTool implements Tool, GrantKeyed {
    public const NAME = 'server_add';

    public function __construct(
        private readonly ServerRepository $servers,
        private readonly Vault $vault,
    ) {}

    public function definition(): ToolDefinition {
        return new ToolDefinition(self::NAME, t('tool.server_add.dobavlyaet_server_v_reestr_zhdet')
            . t('tool.server_add.zakrytym_klyuchom_sam_sekret_vladelets')
            . t('tool.server_add.klyuch_hosta_vladelets_podtverdit_pri')
            . t('tool.server_add.prilozheniya_secret_imya_cherez_proksi'), [
            'type' => 'object',
            'properties' => [
                'name' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 64],
                'host' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 253],
                'port' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 65535],
                'user' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 64],
                'secret' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 64],
                'group' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 32],
                'route' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 80],
            ],
            'required' => ['name', 'host', 'user', 'secret'],
            'additionalProperties' => false,
        ]);
    }

    public function danger(array $args): DangerClass {
        return DangerClass::Irreversible;
    }

    /**
     * spec: brief/agent-loop.md#разрешения-навсегда — every irreversible action can be allowed for good; the key names the
     * action and its target (owner's decision 2026-11-17)
     */
    public function grantKey(array $args): ?string {
        $name = mb_strtolower(trim(Args::string($args, 'name') ?? ''));
        return $name === '' ? null : 'server-add:' . $name;
    }

    public function describe(array $args): string {
        return sprintf('server_add %s %s@%s', Args::string($args, 'name') ?? '', Args::string($args, 'user') ?? '', Args::string($args, 'host') ?? '');
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
            return ToolResult::error(t('tool.server_add.imya_servera_bukvy_tsifry_tochka'));
        }
        if (preg_match('~^[A-Za-z0-9.:\-\[\]]+$~', $host) !== 1 || preg_match('~^[A-Za-z0-9._-]+$~', $user) !== 1) {
            return ToolResult::error(t('tool.server_add.host_imya_ili_adres_polzovatel'));
        }
        $secret = $this->vault->id(Args::string($args, 'secret') ?? '');
        if ($secret === null) {
            return ToolResult::error(t('tool.server_add.takogo_sekreta_net_vladelets_kladet'));
        }
        $route = Args::string($args, 'route');
        if ($route !== null && $route !== 'direct' && $route !== 'vless' && preg_match('~^(secret|via):[\p{L}\p{N}][\p{L}\p{N}._-]{0,63}$~u', $route) !== 1) {
            return ToolResult::error(t('tool.server_add.route_vless_secret_imya_sekreta'));
        }
        try {
            $server = $this->servers->add($name, trim($host, '[]'), Args::int($args, 'port') ?? 22, $user, $secret, Args::string($args, 'group') ?? 'default', $route === 'direct' ? null : $route);
        } catch (DomainException $e) {
            return ToolResult::error($e->getMessage());
        }
        return ToolResult::ok(sprintf(t('tool.server_add.server_dobavlen_pri_pervom_podklyuchenii'), $server->name, $server->username, $server->address()));
    }
}
