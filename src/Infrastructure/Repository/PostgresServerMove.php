<?php

declare(strict_types=1);

namespace OpenCCK\Infrastructure\Repository;

use Amp\Postgres\PostgresLink;
use OpenCCK\App\Server\SshMove;
use Throwable;

/**
 * The former registry of servers as the move into SSH entries reads it: the servers not moved yet with the name, kind
 * and keeper of their secret (never its value), the mark of a moved one, and the grants of server tools renamed.
 *
 * spec: brief/servers.md#ssh-как-предустановленный-mcp
 */
final class PostgresServerMove {
    public function __construct(private readonly PostgresLink $db) {}

    /** @return list<array{name: string, host: string, port: int, user: string, group: string, route: ?string, hostKey: ?string, secret: ?string, kind: ?string, holder: ?int}> */
    public function rows(): array {
        $rows = [];
        $result = $this->db->query(
            'SELECT s.name, s.host, s.port, s.username, s.server_group, s.route, s.host_key, k.name AS secret, k.kind, k.principal_id
             FROM servers s LEFT JOIN secrets k ON k.id = s.secret_id WHERE s.moved_at IS NULL ORDER BY s.id',
        );
        foreach ($result as $row) {
            [$name, $host, $port, $user, $group] = [$row['name'] ?? null, $row['host'] ?? null, $row['port'] ?? null, $row['username'] ?? null, $row['server_group'] ?? null];
            if (!is_string($name) || !is_string($host) || !is_int($port) || !is_string($user) || !is_string($group)) {
                continue;
            }
            $text = static fn(string $column): ?string => isset($row[$column]) && is_string($row[$column]) ? $row[$column] : null;
            $holder = $row['principal_id'] ?? null;
            $rows[] = ['name' => $name, 'host' => $host, 'port' => $port, 'user' => $user, 'group' => $group, 'route' => $text('route'), 'hostKey' => $text('host_key'), 'secret' => $text('secret'), 'kind' => $text('kind'), 'holder' => is_int($holder) ? $holder : null];
        }
        return $rows;
    }

    public function moved(string $name): void {
        $this->db->execute('UPDATE servers SET moved_at = now() WHERE lower(name) = lower(?)', [$name]);
    }

    /** The grants «for good» of the server tools, renamed after the entry; a grant the new name already has stays as it was. */
    public function grants(string $from, string $to): int {
        if ($from === $to) {
            return 0;
        }
        $renamed = 0;
        foreach ($this->db->query('SELECT id, key FROM approval_grants WHERE revoked_at IS NULL') as $row) {
            $id = $row['id'] ?? null;
            $key = $row['key'] ?? null;
            $new = is_int($id) && is_string($key) ? SshMove::grant($key, $from, $to) : null;
            if ($new === null || $new === $key) {
                continue;
            }
            try {
                $this->db->execute('UPDATE approval_grants SET key = ? WHERE id = ?', [$new, $id]);
                $renamed++;
            } catch (Throwable) {
                // the same grant under the new name is there already
            }
        }
        return $renamed;
    }
}
