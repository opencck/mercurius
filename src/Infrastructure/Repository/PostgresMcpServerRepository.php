<?php

declare(strict_types=1);

namespace OpenCCK\Infrastructure\Repository;

use Amp\Postgres\PostgresLink;
use Amp\Sql\SqlQueryError;
use DomainException;
use OpenCCK\Domain\Mcp\McpServer;
use OpenCCK\Domain\Repository\McpServerRepository;
use UnexpectedValueException;

final class PostgresMcpServerRepository implements McpServerRepository {
    private const COLUMNS = 'id, name, url, secret, status, tools_hash, note, tools_include, tools_exclude';

    public function __construct(private readonly PostgresLink $db) {}

    public function add(string $name, string $url, ?string $secret): McpServer {
        try {
            $row = $this->db->execute('INSERT INTO mcp_servers (name, url, secret) VALUES (?, ?, ?) RETURNING ' . self::COLUMNS, [$name, $url, $secret])->fetchRow();
        } catch (SqlQueryError) {
            throw new DomainException(sprintf('MCP-сервер «%s» уже есть.', $name));
        }
        return self::server($row ?? throw new UnexpectedValueException('MCP server was not added'));
    }

    public function find(string $name): ?McpServer {
        $row = $this->db->execute('SELECT ' . self::COLUMNS . ' FROM mcp_servers WHERE name = ?', [$name])->fetchRow();
        return $row === null ? null : self::server($row);
    }

    public function all(): array {
        $found = [];
        foreach ($this->db->query('SELECT ' . self::COLUMNS . ' FROM mcp_servers ORDER BY name') as $row) {
            $found[] = self::server($row);
        }
        return $found;
    }

    public function update(string $name, string $status, ?string $toolsHash, string $note = ''): ?McpServer {
        $row = $this->db->execute(
            'UPDATE mcp_servers SET status = ?, tools_hash = coalesce(?, tools_hash), note = ? WHERE name = ? RETURNING ' . self::COLUMNS,
            [$status, $toolsHash, $note, $name],
        )->fetchRow();
        return $row === null ? null : self::server($row);
    }

    public function setFilter(string $name, ?string $include, string $exclude): ?McpServer {
        $row = $this->db->execute('UPDATE mcp_servers SET tools_include = ?, tools_exclude = ? WHERE name = ? RETURNING ' . self::COLUMNS, [$include, $exclude, $name])->fetchRow();
        return $row === null ? null : self::server($row);
    }

    public function remove(string $name): bool {
        return $this->db->execute('DELETE FROM mcp_servers WHERE name = ? RETURNING id', [$name])->fetchRow() !== null;
    }

    /** @param array<string, mixed> $row */
    private static function server(array $row): McpServer {
        $str = static fn(string $k): ?string => isset($row[$k]) && is_string($row[$k]) ? $row[$k] : null;
        $id = $row['id'] ?? null;
        if (!is_int($id) || $str('name') === null || $str('url') === null || $str('status') === null) {
            throw new UnexpectedValueException('Malformed MCP server row');
        }
        return new McpServer($id, (string) $str('name'), (string) $str('url'), $str('secret'), (string) $str('status'), $str('tools_hash'), $str('note') ?? '', include: $str('tools_include'), exclude: $str('tools_exclude') ?? '');
    }
}
