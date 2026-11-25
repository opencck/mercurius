<?php

declare(strict_types=1);

namespace OpenCCK\Infrastructure\Repository;

use Amp\Postgres\PostgresLink;
use OpenCCK\Domain\Repository\SecretInheritanceRepository;

/** spec: brief/skills-mcp.md#подстановка-секретов */
final class PostgresSecretInheritanceRepository implements SecretInheritanceRepository {
    public function __construct(private readonly PostgresLink $db) {}

    public function of(string $context): array {
        $row = $this->db->execute('SELECT owner, agent FROM secret_inheritance WHERE context = ?', [$context])->fetchRow();
        return ['owner' => ($row['owner'] ?? false) === true, 'agent' => ($row['agent'] ?? false) === true];
    }

    public function set(string $context, string $layer, bool $on): void {
        $column = $layer === 'owner' ? 'owner' : 'agent';
        $this->db->execute(
            "INSERT INTO secret_inheritance (context, $column) VALUES (?, ?) ON CONFLICT (context) DO UPDATE SET $column = EXCLUDED.$column",
            [$context, $on],
        );
    }
}
