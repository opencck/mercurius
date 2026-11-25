<?php

declare(strict_types=1);

namespace OpenCCK\Infrastructure\Repository;

use Amp\Postgres\PostgresLink;
use OpenCCK\Domain\Repository\SecretInheritanceRepository;

/** spec: brief/skills-mcp.md#подстановка-секретов */
final class PostgresSecretInheritanceRepository implements SecretInheritanceRepository {
    public function __construct(private readonly PostgresLink $db) {}

    public function of(string $context): array {
        $row = $this->db->execute('SELECT owner, agent, owner_by FROM secret_inheritance WHERE context = ?', [$context])->fetchRow();
        $by = $row['owner_by'] ?? null;
        return ['owner' => ($row['owner'] ?? false) === true, 'agent' => ($row['agent'] ?? false) === true, 'by' => is_int($by) ? $by : null];
    }

    public function set(string $context, string $layer, bool $on, ?int $by = null): void {
        if ($layer === 'owner') {
            $this->db->execute(
                'INSERT INTO secret_inheritance (context, owner, owner_by) VALUES (?, ?, ?) ON CONFLICT (context) DO UPDATE SET owner = EXCLUDED.owner, owner_by = EXCLUDED.owner_by',
                [$context, $on, $by],
            );
            return;
        }
        $this->db->execute(
            'INSERT INTO secret_inheritance (context, agent) VALUES (?, ?) ON CONFLICT (context) DO UPDATE SET agent = EXCLUDED.agent',
            [$context, $on],
        );
    }
}
