<?php

declare(strict_types=1);

namespace OpenCCK\App\Server;

use Closure;
use OpenCCK\App\Mcp\McpEntry;
use OpenCCK\Domain\Repository\SshHostKeyRepository;
use Psr\Log\LoggerInterface;
use Throwable;

use function Mercurius\I18n\t;

/**
 * The move of the former registry of servers into SSH entries, once per server: the entry goes into the file of whoever
 * keeps the server's secret and is approved for the owners (it is the server they added), the host keys they trusted
 * are pinned by the entry, the grants «for good» follow the name of the entry. With our MCP not installed yet the
 * servers wait; a server that did not move stays as it was and is tried at the next start.
 *
 * spec: brief/servers.md#ssh-как-предустановленный-mcp — no new host key card after the move
 */
final class SshMigration {
    /**
     * @param Closure(): list<array{name: string, host: string, port: int, user: string, group: string, route: ?string, hostKey: ?string, secret: ?string, kind: ?string, holder: ?int}> $rows the servers not moved yet
     * @param Closure(): ?string $install the name of our installed MCP; null — not installed
     * @param Closure(string, string): void $allow the install may run in the context
     * @param Closure(string, McpEntry): void $put the entry into the file of the context
     * @param Closure(string, string): ?string $approve approves the entry; null — done, else why not
     * @param Closure(string, string): int $grants the grants of the server tools to the new name
     * @param Closure(string): void $moved the server is marked moved
     */
    public function __construct(
        private readonly Closure $rows,
        private readonly Closure $install,
        private readonly Closure $allow,
        private readonly Closure $put,
        private readonly Closure $approve,
        private readonly Closure $grants,
        private readonly Closure $moved,
        private readonly SshHostKeyRepository $keys,
        private readonly LoggerInterface $logger,
    ) {}

    /** @return list<string> what happened to each server, for the owners; empty — nothing to move */
    public function run(): array {
        $rows = ($this->rows)();
        if ($rows === []) {
            return [];
        }
        $install = ($this->install)();
        if ($install === null) {
            return [sprintf(t('server.migration.waits'), count($rows))];
        }
        $byName = [];
        foreach ($rows as $row) {
            $byName[mb_strtolower($row['name'])] = $row;
        }
        $lines = [];
        foreach ($rows as $row) {
            $move = SshMove::of($row, $byName, $install);
            if ($move === null) {
                $lines[] = sprintf(t('server.migration.skipped'), $row['name']);
                continue;
            }
            try {
                ($this->allow)($install, $move->context);
                ($this->put)($move->context, McpEntry::fromArray($move->name, $move->entry));
                $problem = ($this->approve)($move->context, $move->name);
            } catch (Throwable $e) {
                $problem = $e->getMessage();
            }
            if ($problem !== null) {
                $this->logger->warning('A server did not move into an SSH entry', ['server' => $row['name'], 'problem' => mb_substr($problem, 0, 200)]);
                $lines[] = sprintf(t('server.migration.failed'), $row['name'], $move->context, mb_substr($problem, 0, 200));
                continue;
            }
            $key = $move->context . '|' . $move->name;
            foreach ($move->keys as [$address, $hostKey]) {
                $this->keys->pin($key, $address, $hostKey);
            }
            $grants = ($this->grants)($row['name'], $move->name);
            ($this->moved)($row['name']);
            $this->logger->info('A server moved into an SSH entry', ['server' => $row['name'], 'entry' => $key, 'grants' => $grants]);
            $lines[] = sprintf(t('server.migration.moved'), $row['name'], $move->name, $move->context);
        }
        return $lines;
    }
}
