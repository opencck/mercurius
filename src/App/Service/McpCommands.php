<?php

declare(strict_types=1);

namespace OpenCCK\App\Service;

use Amp\TimeoutCancellation;
use DomainException;
use OpenCCK\App\Mcp\McpManager;
use OpenCCK\Domain\Mcp\McpServer;
use OpenCCK\Domain\Repository\McpServerRepository;

/**
 * The owner connects MCP servers from the chat: the list, adding, approving (turning on), turning off, removing.
 *
 * spec: BRIEF.md#скиллы-и-mcp — managed from the channel, the owner only
 */
final class McpCommands {
    public function __construct(
        private readonly McpManager $manager,
        private readonly McpServerRepository $servers,
    ) {}

    public function handle(string $argument): string {
        $parts = preg_split('~\s+~', trim($argument)) ?: [];
        $action = $parts[0] ?? '';
        $name = $parts[1] ?? '';
        try {
            return match ($action) {
                '' => $this->list(),
                'add' => $this->add($name, $parts[2] ?? '', $parts[3] ?? null),
                'on' => $this->manager->approve($name, new TimeoutCancellation(60)),
                'off' => $this->manager->disable($name) ? sprintf('MCP-сервер «%s» выключен.', $name) : sprintf('MCP-сервера «%s» нет.', $name),
                'remove' => $this->manager->remove($name) ? sprintf('MCP-сервер «%s» удалён.', $name) : sprintf('MCP-сервера «%s» нет.', $name),
                default => '/mcp — список; /mcp add <имя> <url> [секрет с токеном]; /mcp on <имя> — одобрить; /mcp off <имя>; /mcp remove <имя>',
            };
        } catch (DomainException $e) {
            return $e->getMessage();
        }
    }

    private function add(string $name, string $url, ?string $secret): string {
        $server = $this->manager->add($name, $url, $secret);
        return sprintf('MCP-сервер «%s» добавлен и ждёт одобрения: /mcp on %s — подключить его инструменты.', $server->name, $server->name);
    }

    private function list(): string {
        $all = $this->servers->all();
        if ($all === []) {
            return 'MCP-серверов нет. /mcp add <имя> <url> [секрет с токеном]';
        }
        return "MCP-серверы:\n" . implode("\n", array_map(fn(McpServer $s): string => sprintf(
            '%s — %s, %s%s%s',
            $s->name,
            $s->url,
            match ($s->status) {
                McpServer::ACTIVE => 'включён',
                McpServer::DISABLED => 'выключен',
                default => 'ждёт одобрения',
            },
            $s->note === '' ? '' : ' (' . $s->note . ')',
            ($tools = $this->manager->tools($s->name)) === [] ? '' : ': ' . implode(', ', $tools),
        ), $all));
    }
}
