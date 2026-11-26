<?php

declare(strict_types=1);

namespace OpenCCK\Infrastructure\Repository;

use Amp\Postgres\PostgresLink;
use Amp\Sql\SqlQueryError;
use DomainException;
use OpenCCK\Domain\Mcp\McpServer;
use OpenCCK\Domain\Repository\McpServerRepository;
use UnexpectedValueException;

use function Mercurius\I18n\t;

/**
 * spec: brief/skills-mcp.md#три-слоя-установленный-mcp-запись-подключение — connections in contexts, with their state
 */
final class PostgresMcpServerRepository implements McpServerRepository {
    private const COLUMNS = 'id, context, name, url, secret, state, tools_hash, reason, tools_include, tools_exclude, extract(epoch FROM next_try)::float8 AS next_try, entry_hash, entry::text AS entry';

    public function __construct(private readonly PostgresLink $db) {}

    public function add(string $context, string $name, string $url, ?string $secret): McpServer {
        try {
            $row = $this->db->execute('INSERT INTO mcp_connections (context, name, url, secret) VALUES (?, ?, ?, ?) RETURNING ' . self::COLUMNS, [$context, $name, $url, $secret])->fetchRow();
        } catch (SqlQueryError) {
            throw new DomainException(sprintf(t('repository.postgres_mcp_server.mcp_server_uzhe_est'), $name));
        }
        return self::server($row ?? throw new UnexpectedValueException('MCP connection was not added'));
    }

    public function find(string $context, string $name): ?McpServer {
        $row = $this->db->execute('SELECT ' . self::COLUMNS . ' FROM mcp_connections WHERE context = ? AND name = ?', [$context, $name])->fetchRow();
        return $row === null ? null : self::server($row);
    }

    /** @return list<McpServer> */
    public function all(): array {
        $found = [];
        foreach ($this->db->query('SELECT ' . self::COLUMNS . ' FROM mcp_connections ORDER BY context, name') as $row) {
            $found[] = self::server($row);
        }
        return $found;
    }

    public function inContext(string $context): array {
        $found = [];
        foreach ($this->db->execute('SELECT ' . self::COLUMNS . ' FROM mcp_connections WHERE context = ? ORDER BY name', [$context]) as $row) {
            $found[] = self::server($row);
        }
        return $found;
    }

    public function update(string $context, string $name, string $state, ?string $toolsHash, string $reason = '', ?float $nextTry = null): ?McpServer {
        $row = $this->db->execute(
            'UPDATE mcp_connections SET state = ?, tools_hash = coalesce(?, tools_hash), reason = ?, next_try = to_timestamp(?::float8)
             WHERE context = ? AND name = ? RETURNING ' . self::COLUMNS,
            [$state, $toolsHash, $reason, $nextTry, $context, $name],
        )->fetchRow();
        return $row === null ? null : self::server($row);
    }

    public function setFilter(string $context, string $name, ?string $include, string $exclude): ?McpServer {
        $row = $this->db->execute('UPDATE mcp_connections SET tools_include = ?, tools_exclude = ? WHERE context = ? AND name = ? RETURNING ' . self::COLUMNS, [$include, $exclude, $context, $name])->fetchRow();
        return $row === null ? null : self::server($row);
    }

    public function setAddress(string $context, string $name, string $url, ?string $secret): ?McpServer {
        $row = $this->db->execute('UPDATE mcp_connections SET url = ?, secret = ? WHERE context = ? AND name = ? RETURNING ' . self::COLUMNS, [$url, $secret, $context, $name])->fetchRow();
        return $row === null ? null : self::server($row);
    }

    public function setEntry(string $context, string $name, array $entry): ?McpServer {
        $row = $this->db->execute(
            'UPDATE mcp_connections SET entry = ?::jsonb WHERE context = ? AND name = ? RETURNING ' . self::COLUMNS,
            [json_encode($entry, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $context, $name],
        )->fetchRow();
        return $row === null ? null : self::server($row);
    }

    public function asked(string $context, string $name): ?string {
        $value = $this->db->execute('SELECT asked_hash FROM mcp_connections WHERE context = ? AND name = ?', [$context, $name])->fetchRow()['asked_hash'] ?? null;
        return is_string($value) ? $value : null;
    }

    public function setAsked(string $context, string $name, string $entryHash): void {
        $this->db->execute('UPDATE mcp_connections SET asked_hash = ? WHERE context = ? AND name = ?', [$entryHash, $context, $name]);
    }

    public function setApproval(string $context, string $name, ?string $entryHash, ?int $by, ?array $entry = null): ?McpServer {
        $row = $this->db->execute(
            'UPDATE mcp_connections SET entry_hash = ?, approved_by = ?, approved_at = CASE WHEN ?::text IS NULL THEN NULL ELSE now() END, entry = ?::jsonb
             WHERE context = ? AND name = ? RETURNING ' . self::COLUMNS,
            [$entryHash, $by, $entryHash, $entry === null ? null : json_encode($entry, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $context, $name],
        )->fetchRow();
        return $row === null ? null : self::server($row);
    }

    public function saveTools(string $context, string $name, string $listedBy, array $tools): void {
        $this->db->execute(
            'UPDATE mcp_connections SET tools = ?, tools_entry = ? WHERE context = ? AND name = ?',
            [json_encode($tools, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $listedBy, $context, $name],
        );
    }

    public function cachedTools(string $context, string $name, string $listedBy): ?array {
        $row = $this->db->execute('SELECT tools FROM mcp_connections WHERE context = ? AND name = ? AND tools_entry = ?', [$context, $name, $listedBy])->fetchRow();
        $json = $row['tools'] ?? null;
        if (!is_string($json)) {
            return null;
        }
        /** @psalm-suppress MixedAssignment the kept list is any JSON; each tool is checked below */
        $list = json_decode($json, true);
        if (!is_array($list) || !array_is_list($list)) {
            return null;
        }
        $tools = [];
        /** @psalm-suppress MixedAssignment */
        foreach ($list as $tool) {
            if (!is_array($tool)) {
                return null;
            }
            /** @psalm-suppress MixedAssignment */
            [$name, $description, $schema, $annotations] = [$tool['name'] ?? null, $tool['description'] ?? null, $tool['schema'] ?? null, $tool['annotations'] ?? null];
            if (!is_string($name) || !is_string($description) || !is_array($schema) || !is_array($annotations)) {
                return null;
            }
            $tools[] = ['name' => $name, 'description' => $description, 'schema' => $schema, 'annotations' => $annotations];
        }
        return $tools;
    }

    public function remove(string $context, string $name): bool {
        return $this->db->execute('DELETE FROM mcp_connections WHERE context = ? AND name = ? RETURNING id', [$context, $name])->fetchRow() !== null;
    }

    private static function seconds(mixed $value): ?float {
        return is_float($value) || is_int($value) ? (float) $value : null;
    }

    /** @param array<string, mixed> $row */
    private static function server(array $row): McpServer {
        $str = static fn(string $k): ?string => isset($row[$k]) && is_string($row[$k]) ? $row[$k] : null;
        $id = $row['id'] ?? null;
        if (!is_int($id) || $str('name') === null || $str('url') === null || $str('state') === null || $str('context') === null) {
            throw new UnexpectedValueException('Malformed MCP connection row');
        }
        return new McpServer(
            $id,
            (string) $str('name'),
            (string) $str('url'),
            $str('secret'),
            (string) $str('state'),
            $str('tools_hash'),
            $str('reason') ?? '',
            include: $str('tools_include'),
            exclude: $str('tools_exclude') ?? '',
            context: (string) $str('context'),
            nextTry: self::seconds($row['next_try'] ?? null),
            approvedHash: $str('entry_hash'),
            entry: self::entry($str('entry')),
        );
    }

    /** @return array<array-key, mixed>|null */
    private static function entry(?string $json): ?array {
        if ($json === null) {
            return null;
        }
        /** @psalm-suppress MixedAssignment the stored entry is any JSON; McpEntry checks it when it is used */
        $entry = json_decode($json, true);
        return is_array($entry) ? $entry : null;
    }
}
