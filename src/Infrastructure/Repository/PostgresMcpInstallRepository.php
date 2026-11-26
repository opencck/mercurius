<?php

declare(strict_types=1);

namespace OpenCCK\Infrastructure\Repository;

use Amp\Postgres\PostgresLink;
use Amp\Sql\SqlQueryError;
use DomainException;
use OpenCCK\Domain\Mcp\McpInstall;
use OpenCCK\Domain\Repository\McpInstallRepository;
use UnexpectedValueException;

/** spec: brief/skills-mcp.md#три-слоя-установленный-mcp-запись-подключение — installed MCP servers */
final class PostgresMcpInstallRepository implements McpInstallRepository {
    private const COLUMNS = 'id, name, source, installed_by, revision, previous, tools_hash, tool_classes::text AS classes, entry::text AS entry, latest, steps::text AS steps';

    public function __construct(private readonly PostgresLink $db) {}

    public function add(string $name, string $source, int $installedBy): McpInstall {
        try {
            $row = $this->db->execute('INSERT INTO mcp_installs (name, source, installed_by) VALUES (?, ?, ?) RETURNING ' . self::COLUMNS, [$name, $source, $installedBy])->fetchRow();
        } catch (SqlQueryError) {
            throw new DomainException('taken');
        }
        return self::install($row ?? throw new UnexpectedValueException('MCP install was not added'));
    }

    public function find(string $name): ?McpInstall {
        $row = $this->db->execute('SELECT ' . self::COLUMNS . ' FROM mcp_installs WHERE name = ?', [$name])->fetchRow();
        return $row === null ? null : self::install($row);
    }

    public function all(): array {
        $found = [];
        foreach ($this->db->query('SELECT ' . self::COLUMNS . ' FROM mcp_installs WHERE NOT builtin ORDER BY name') as $row) {
            $found[] = self::install($row);
        }
        return $found;
    }

    public function setRevision(string $name, string $revision, string $toolsHash, array $classes, array $entry): void {
        $this->db->execute(
            'UPDATE mcp_installs SET previous = revision, revision = ?, tools_hash = ?, tool_classes = ?::jsonb, entry = ?::jsonb WHERE name = ?',
            [$revision, $toolsHash, self::json($classes), self::json($entry), $name],
        );
    }

    public function setSteps(string $name, array $steps): void {
        $this->db->execute('UPDATE mcp_installs SET steps = ?::jsonb WHERE name = ?', [json_encode($steps, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $name]);
    }

    public function environment(string $name): ?string {
        $value = $this->db->execute('SELECT environment FROM mcp_installs WHERE name = ?', [$name])->fetchRow()['environment'] ?? null;
        return is_string($value) ? $value : null;
    }

    public function setEnvironment(string $name, string $environment): void {
        $this->db->execute('UPDATE mcp_installs SET environment = ? WHERE name = ?', [$environment, $name]);
    }

    public function setLatest(string $name, ?string $latest): void {
        $this->db->execute('UPDATE mcp_installs SET latest = ? WHERE name = ?', [$latest, $name]);
    }

    public function allow(string $name, string $context, bool $allowed): void {
        $allowed
            ? $this->db->execute('INSERT INTO mcp_install_contexts (install, context) VALUES (?, ?) ON CONFLICT DO NOTHING', [$name, $context])
            : $this->db->execute('DELETE FROM mcp_install_contexts WHERE install = ? AND context = ?', [$name, $context]);
    }

    public function contexts(string $name): array {
        $found = [];
        foreach ($this->db->execute('SELECT context FROM mcp_install_contexts WHERE install = ? ORDER BY context', [$name]) as $row) {
            /** @psalm-suppress MixedAssignment */
            $context = $row['context'] ?? null;
            if (is_string($context)) {
                $found[] = $context;
            }
        }
        return $found;
    }

    public function remove(string $name): bool {
        return $this->db->execute('DELETE FROM mcp_installs WHERE name = ? RETURNING name', [$name])->fetchRow() !== null;
    }

    /** @param array<array-key, mixed> $value */
    private static function json(array $value): string {
        return json_encode($value === [] ? new \stdClass() : $value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /** @param array<string, mixed> $row */
    private static function install(array $row): McpInstall {
        [$id, $name, $source] = [$row['id'] ?? null, $row['name'] ?? null, $row['source'] ?? null];
        if (!is_int($id) || !is_string($name) || !is_string($source)) {
            throw new UnexpectedValueException('Malformed MCP install row');
        }
        $text = static fn(string $key): ?string => isset($row[$key]) && is_string($row[$key]) ? $row[$key] : null;
        $classes = [];
        /** @psalm-suppress MixedAssignment the stored classes are any JSON; only string pairs are taken */
        foreach ((array) json_decode($text('classes') ?? '{}', true) as $tool => $class) {
            if (is_string($tool) && is_string($class)) {
                $classes[$tool] = $class;
            }
        }
        /** @psalm-suppress MixedAssignment */
        $entry = json_decode($text('entry') ?? 'null', true);
        /** @psalm-suppress MixedAssignment */
        $by = $row['installed_by'] ?? null;
        $steps = [];
        /** @psalm-suppress MixedAssignment the stored steps are any JSON; only strings are taken */
        foreach ((array) json_decode($text('steps') ?? '[]', true) as $step) {
            if (is_string($step)) {
                $steps[] = $step;
            }
        }
        return new McpInstall($id, $name, $source, is_int($by) ? $by : 0, $text('revision'), $text('previous'), $text('tools_hash'), $classes, is_array($entry) ? $entry : null, $text('latest'), $steps);
    }
}
