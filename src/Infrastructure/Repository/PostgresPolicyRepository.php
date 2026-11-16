<?php

declare(strict_types=1);

namespace OpenCCK\Infrastructure\Repository;

use Amp\Postgres\PostgresLink;
use OpenCCK\Domain\Repository\PolicyRepository;
use UnexpectedValueException;

final class PostgresPolicyRepository implements PolicyRepository {
    public function __construct(private readonly PostgresLink $db) {}

    public function find(int $principalId): ?array {
        $row = $this->db->execute('SELECT policy::text AS policy FROM principal_policies WHERE principal_id = ?', [$principalId])->fetchRow();
        if ($row === null) {
            return null;
        }
        $json = $row['policy'] ?? null;
        /** @var mixed $decoded */
        $decoded = is_string($json) ? json_decode($json, true, 512, JSON_THROW_ON_ERROR) : null;
        if (!is_array($decoded)) {
            throw new UnexpectedValueException('Malformed principal_policies row');
        }
        return array_combine(array_map('strval', array_keys($decoded)), array_values($decoded));
    }

    public function save(int $principalId, array $overrides): void {
        $this->db->execute(
            'INSERT INTO principal_policies (principal_id, policy) VALUES (?, ?::jsonb)
             ON CONFLICT (principal_id) DO UPDATE SET policy = EXCLUDED.policy, updated_at = now()',
            [$principalId, json_encode((object) $overrides, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)],
        );
    }
}
