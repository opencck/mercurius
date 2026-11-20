<?php

declare(strict_types=1);

namespace OpenCCK\App\Service;

use Amp\TimeoutCancellation;
use DomainException;
use OpenCCK\App\Mcp\McpManager;
use OpenCCK\Domain\Mcp\McpServer;
use OpenCCK\Domain\Repository\McpServerRepository;

use function Mercurius\I18n\t;

/**
 * The owner connects MCP servers from the chat: the list, adding, approving (turning on), turning off, removing.
 *
 * spec: brief/skills-mcp.md#скиллы-и-mcp — managed from the channel, the owner only
 */
final class McpCommands {
    public function __construct(
        private readonly McpManager $manager,
        private readonly McpServerRepository $servers,
    ) {}

    public function handle(string $argument): string {
        // the command of a stdio server has spaces of its own: everything after ' -- ' is it
        [$head, $command] = array_pad(explode(' -- ', trim($argument), 2), 2, null);
        $parts = preg_split('~\s+~', trim((string) $head)) ?: [];
        $action = $parts[0] ?? '';
        $name = $parts[1] ?? '';
        try {
            return match ($action) {
                '' => $this->list(),
                'add' => $this->add($name, $parts[2] ?? '', $parts[3] ?? null),
                'stdio' => $command === null || trim($command) === '' || $name === '' ? t('service.mcp_commands.stdio_usage') : $this->add($name, 'stdio:' . trim($command), $parts[2] ?? null),
                'on' => $this->manager->approve($name, new TimeoutCancellation(60)),
                'off' => $this->manager->disable($name) ? sprintf(t('service.mcp_commands.mcp_server_vyklyuchen'), $name) : sprintf(t('service.mcp_commands.mcp_servera_net'), $name),
                'remove' => $this->manager->remove($name) ? sprintf(t('service.mcp_commands.mcp_server_udalen'), $name) : sprintf(t('service.mcp_commands.mcp_servera_net'), $name),
                'tools' => $this->tools($name, $parts[2] ?? '', $parts[3] ?? ''),
                default => t('service.mcp_commands.mcp_spisok_mcp_add_imya')
                    . t('service.mcp_commands.mcp_tools_imya_include_exclude'),
            };
        } catch (DomainException $e) {
            return $e->getMessage();
        }
    }

    /** /mcp tools <name> include a,b* | exclude c* | all */
    private function tools(string $name, string $mode, string $masks): string {
        $server = match ($mode) {
            'include' => $this->manager->filter($name, $masks, '', new TimeoutCancellation(60)),
            'exclude' => $this->manager->filter($name, null, $masks, new TimeoutCancellation(60)),
            'all' => $this->manager->filter($name, null, '', new TimeoutCancellation(60)),
            default => null,
        };
        if ($server === null) {
            return t('service.mcp_commands.mcp_tools_imya_include_imena');
        }
        return sprintf(t('service.mcp_commands.instrumenty_mcp_servera'), $server->name, self::filterText($server));
    }

    private static function filterText(McpServer $server): string {
        return match (true) {
            $server->include === '' => t('service.mcp_commands.ni_odnogo'),
            $server->include !== null => t('service.mcp_commands.tolko') . $server->include,
            $server->exclude !== '' => t('service.mcp_commands.vse_krome') . $server->exclude,
            default => t('service.mcp_commands.vse'),
        };
    }

    private function add(string $name, string $url, ?string $secret): string {
        $server = $this->manager->add($name, $url, $secret);
        return sprintf(t('service.mcp_commands.mcp_server_dobavlen_i_zhdet'), $server->name, $server->name);
    }

    private function list(): string {
        $all = $this->servers->all();
        if ($all === []) {
            return t('service.mcp_commands.mcp_serverov_net_mcp_add');
        }
        return t('service.mcp_commands.mcp_servery') . implode("\n", array_map(fn(McpServer $s): string => sprintf(
            '%s — %s, %s%s%s',
            $s->name,
            $s->url,
            match ($s->status) {
                McpServer::ACTIVE => t('service.mcp_commands.vklyuchen'),
                McpServer::DISABLED => t('service.mcp_commands.vyklyuchen'),
                default => t('service.mcp_commands.zhdet_odobreniya'),
            },
            $s->note === '' ? '' : ' (' . $s->note . ')',
            ($tools = $this->manager->tools($s->name)) === [] ? '' : ': ' . implode(', ', $tools),
        ), $all));
    }
}
