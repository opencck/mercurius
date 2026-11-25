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
 * MCP servers from the chat: the list, adding, approving (turning on), turning off, removing.
 *
 * spec: brief/skills-mcp.md#mcp-серверы-подключение-и-доверие — managed from the channel
 * spec: brief/participants.md#свои-роли — by whoever has the permission mcp
 */
final class McpCommands {
    public function __construct(
        private readonly McpManager $manager,
        private readonly McpServerRepository $servers,
    ) {}

    /**
     * spec: brief/skills-mcp.md#три-слоя-установленный-mcp-запись-подключение — the commands work in the private
     *       context of the one who writes, member:<id>
     */
    public function handle(string $argument, \OpenCCK\Domain\Entity\Member $who): string {
        $context = 'member:' . $who->id;
        // the command of a stdio server has spaces of its own: everything after ' -- ' is it
        [$head, $command] = array_pad(explode(' -- ', trim($argument), 2), 2, null);
        $parts = preg_split('~\s+~', trim((string) $head)) ?: [];
        $action = $parts[0] ?? '';
        $name = $parts[1] ?? '';
        try {
            return match ($action) {
                '' => $this->list($context),
                'add' => $this->add($context, $name, $parts[2] ?? '', $parts[3] ?? null),
                'stdio' => $command === null || trim($command) === '' || $name === '' ? t('service.mcp_commands.stdio_usage') : $this->add($context, $name, 'stdio:' . trim($command), $parts[2] ?? null),
                'on' => $this->manager->approve($context, $name, new TimeoutCancellation(60)),
                'off' => $this->manager->disable($context, $name) ? sprintf(t('service.mcp_commands.mcp_server_vyklyuchen'), $name) : sprintf(t('service.mcp_commands.mcp_servera_net'), $name),
                'remove' => $this->manager->remove($context, $name) ? sprintf(t('service.mcp_commands.mcp_server_udalen'), $name) : sprintf(t('service.mcp_commands.mcp_servera_net'), $name),
                'tools' => $this->tools($context, $name, $parts[2] ?? '', $parts[3] ?? ''),
                default => t('service.mcp_commands.mcp_spisok_mcp_add_imya')
                    . t('service.mcp_commands.mcp_tools_imya_include_exclude'),
            };
        } catch (DomainException $e) {
            return $e->getMessage();
        }
    }

    /** /mcp tools <name> include a,b* | exclude c* | all */
    private function tools(string $context, string $name, string $mode, string $masks): string {
        $server = match ($mode) {
            'include' => $this->manager->filter($context, $name, $masks, '', new TimeoutCancellation(60)),
            'exclude' => $this->manager->filter($context, $name, null, $masks, new TimeoutCancellation(60)),
            'all' => $this->manager->filter($context, $name, null, '', new TimeoutCancellation(60)),
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

    private function add(string $context, string $name, string $url, ?string $secret): string {
        $server = $this->manager->add($context, $name, $url, $secret);
        return sprintf(t('service.mcp_commands.mcp_server_dobavlen_i_zhdet'), $server->name, $server->name);
    }

    private function list(string $context): string {
        $all = $this->servers->inContext($context);
        if ($all === []) {
            return t('service.mcp_commands.mcp_serverov_net_mcp_add');
        }
        return t('service.mcp_commands.mcp_servery') . implode("\n", array_map(fn(McpServer $s): string => sprintf(
            '%s — %s, %s%s%s',
            $s->name,
            $s->url,
            // spec: brief/skills-mcp.md#три-слоя-установленный-mcp-запись-подключение — the state of each connection
            t('service.mcp_commands.state.' . $s->status),
            $s->note === '' ? '' : ' (' . $s->note . ')',
            ($tools = $this->manager->tools($s->key())) === [] ? '' : ': ' . implode(', ', $tools),
        ), $all));
    }
}
