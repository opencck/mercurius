<?php

declare(strict_types=1);

namespace OpenCCK\App\Service;

use Amp\TimeoutCancellation;
use DomainException;
use OpenCCK\App\Mcp\McpEntry;
use OpenCCK\App\Mcp\McpFiles;
use OpenCCK\App\Mcp\McpManager;
use OpenCCK\Domain\Mcp\McpServer;
use OpenCCK\Domain\Repository\McpServerRepository;

use function Mercurius\I18n\t;

/**
 * MCP servers from the chat: the list, adding, approving (turning on), turning off, removing.
 *
 * spec: brief/skills-mcp.md#mcp-серверы-подключение-и-доверие — managed from the channel
 * spec: brief/participants.md#свои-роли — by whoever has the permission mcp
 * spec: brief/skills-mcp.md#файл-mcpjson-и-одобрение-записи — the commands write the context's .mcp.json; on approves
 *       the entry the file holds
 */
final class McpCommands {
    public function __construct(
        private readonly McpManager $manager,
        private readonly McpServerRepository $servers,
        /** null — no sandbox to keep files in: the connections are changed in the store only */
        private readonly ?McpFiles $files = null,
    ) {}

    /**
     * spec: brief/skills-mcp.md#три-слоя-установленный-mcp-запись-подключение — the commands work in the private
     *       context of the one who writes, member:<id>
     */
    public function handle(string $argument, \OpenCCK\Domain\Entity\Member $who): string {
        $context = 'member:' . $who->id;
        // another context: /mcp project:<number>: … or /mcp member:<number>: …
        if (preg_match('~^\s*((?:project|member):\d+):\s*(.*)$~s', $argument, $m) === 1) {
            $context = $m[1];
            $argument = $m[2];
        }
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
                'on' => $this->files?->approve($context, $name, $who) ?? $this->manager->approve($context, $name, new TimeoutCancellation(60)),
                'off' => ($this->files?->switch($context, $name, false) ?? $this->manager->disable($context, $name)) ? sprintf(t('service.mcp_commands.mcp_server_vyklyuchen'), $name) : sprintf(t('service.mcp_commands.mcp_servera_net'), $name),
                'remove' => ($this->files?->drop($context, $name) ?? $this->manager->remove($context, $name)) ? sprintf(t('service.mcp_commands.mcp_server_udalen'), $name) : sprintf(t('service.mcp_commands.mcp_servera_net'), $name),
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

    /** An entry of the context's file from the command: a url with a bearer secret, or a command with one variable. */
    private function add(string $context, string $name, string $url, ?string $secret): string {
        // the checks of the address and of stdio stay the manager's
        $this->manager->checkAddress($url);
        if ($this->files === null) {
            $server = $this->manager->add($context, $name, $url, $secret);
            return sprintf(t('service.mcp_commands.mcp_server_dobavlen_i_zhdet'), $server->name, $server->name);
        }
        if (str_starts_with($url, 'stdio:')) {
            $words = preg_split('~\s+~', trim(substr($url, 6))) ?: [];
            [$secretName, $variable] = array_pad(explode(':', (string) $secret, 2), 2, 'MCP_TOKEN');
            $raw = ['command' => (string) array_shift($words), 'args' => $words, 'env' => $secret === null ? [] : [$variable => '{' . $secretName . '}']];
        } else {
            $raw = ['url' => $url, 'headers' => $secret === null ? [] : ['Authorization' => 'Bearer {' . $secret . '}']];
        }
        try {
            $entry = McpEntry::fromArray($name, $raw);
        } catch (\InvalidArgumentException $e) {
            throw new DomainException($e->getMessage());
        }
        if ($this->servers->find($context, $name) !== null) {
            throw new DomainException(sprintf(t('repository.postgres_mcp_server.mcp_server_uzhe_est'), $name));
        }
        try {
            $this->files->put($context, $entry);
        } catch (\InvalidArgumentException $e) {
            throw new DomainException($e->getMessage());
        }
        // the entry was read at once: an entry that cannot run says why
        foreach ($this->servers->inContext($context) as $server) {
            if ($server->name === $name && $server->status === McpServer::ERROR) {
                return $server->note;
            }
        }
        return sprintf(t('service.mcp_commands.mcp_server_dobavlen_i_zhdet'), $name, $name);
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
