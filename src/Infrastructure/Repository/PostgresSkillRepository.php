<?php

declare(strict_types=1);

namespace OpenCCK\Infrastructure\Repository;

use Amp\Postgres\PostgresLink;
use OpenCCK\Domain\Repository\SkillRepository;
use OpenCCK\Domain\Skill\Skill;
use OpenCCK\Domain\Skill\SkillVersion;
use Throwable;
use UnexpectedValueException;

final class PostgresSkillRepository implements SkillRepository {
    private const SKILL = 'id, name, description, body, status, origin, version';
    private const VERSION = 'v.id, v.skill_id, s.name, v.version, v.description, v.body, v.status, v.reason, v.principal_id';

    public function __construct(private readonly PostgresLink $db) {}

    public function find(string $name): ?Skill {
        $row = $this->db->execute('SELECT ' . self::SKILL . ' FROM skills WHERE name = ?', [$name])->fetchRow();
        return $row === null ? null : self::skill($row);
    }

    public function active(): array {
        return $this->list("SELECT " . self::SKILL . " FROM skills WHERE status = 'active' ORDER BY name");
    }

    public function all(): array {
        return $this->list('SELECT ' . self::SKILL . ' FROM skills ORDER BY name');
    }

    public function propose(string $name, string $description, string $body, string $reason, ?int $principalId, string $origin): SkillVersion {
        $transaction = $this->db->beginTransaction();
        try {
            $transaction->execute('INSERT INTO skills (name, origin) VALUES (?, ?) ON CONFLICT (name) DO NOTHING', [$name, $origin]);
            $skill = $transaction->execute('SELECT id FROM skills WHERE name = ? FOR UPDATE', [$name])->fetchRow();
            $skillId = self::int($skill ?? [], 'id');
            $next = $transaction->execute('SELECT coalesce(max(version), 0) + 1 AS n FROM skill_versions WHERE skill_id = ?', [$skillId])->fetchRow();
            $row = $transaction->execute(
                'INSERT INTO skill_versions (skill_id, version, description, body, reason, principal_id) VALUES (?, ?, ?, ?, ?, ?) RETURNING id',
                [$skillId, self::int($next ?? [], 'n'), $description, $body, $reason, $principalId],
            )->fetchRow();
            $transaction->commit();
            return $this->version(self::int($row ?? [], 'id')) ?? throw new UnexpectedValueException('Skill version was not stored');
        } catch (Throwable $e) {
            if ($transaction->isActive()) {
                $transaction->rollback();
            }
            throw $e;
        }
    }

    public function version(int $id): ?SkillVersion {
        $row = $this->db->execute('SELECT ' . self::VERSION . ' FROM skill_versions v JOIN skills s ON s.id = v.skill_id WHERE v.id = ?', [$id])->fetchRow();
        return $row === null ? null : self::skillVersion($row);
    }

    public function accept(int $versionId): ?Skill {
        $row = $this->db->execute(
            "WITH chosen AS (
                UPDATE skill_versions SET status = 'accepted' WHERE id = ? AND status = 'proposed' RETURNING skill_id, version, description, body
             )
             UPDATE skills s SET description = c.description, body = c.body, version = c.version, status = 'active', updated_at = now()
             FROM chosen c WHERE s.id = c.skill_id RETURNING s.id, s.name, s.description, s.body, s.status, s.origin, s.version",
            [$versionId],
        )->fetchRow();
        return $row === null ? null : self::skill($row);
    }

    public function reject(int $versionId): bool {
        return $this->db->execute("UPDATE skill_versions SET status = 'rejected' WHERE id = ? AND status = 'proposed' RETURNING id", [$versionId])->fetchRow() !== null;
    }

    public function setStatus(string $name, string $status): bool {
        return $this->db->execute("UPDATE skills SET status = ?, updated_at = now() WHERE name = ? AND version > 0 RETURNING id", [$status, $name])->fetchRow() !== null;
    }

    public function history(string $name): array {
        $found = [];
        foreach ($this->db->execute(
            "SELECT " . self::VERSION . " FROM skill_versions v JOIN skills s ON s.id = v.skill_id WHERE s.name = ? AND v.status = 'accepted' ORDER BY v.version DESC",
            [$name],
        ) as $row) {
            $found[] = self::skillVersion($row);
        }
        return $found;
    }

    public function rollback(string $name, int $version): ?Skill {
        $row = $this->db->execute(
            "UPDATE skills s SET description = v.description, body = v.body, version = v.version, updated_at = now()
             FROM skill_versions v WHERE v.skill_id = s.id AND s.name = ? AND v.version = ? AND v.status = 'accepted'
             RETURNING s.id, s.name, s.description, s.body, s.status, s.origin, s.version",
            [$name, $version],
        )->fetchRow();
        return $row === null ? null : self::skill($row);
    }

    /** @return list<Skill> */
    private function list(string $sql): array {
        $found = [];
        foreach ($this->db->query($sql) as $row) {
            $found[] = self::skill($row);
        }
        return $found;
    }

    /** @param array<string, mixed> $row */
    private static function skill(array $row): Skill {
        return new Skill(self::int($row, 'id'), self::str($row, 'name'), self::str($row, 'description'), self::str($row, 'body'), self::str($row, 'status'), self::str($row, 'origin'), self::int($row, 'version'));
    }

    /** @param array<string, mixed> $row */
    private static function skillVersion(array $row): SkillVersion {
        return new SkillVersion(
            self::int($row, 'id'),
            self::int($row, 'skill_id'),
            self::str($row, 'name'),
            self::int($row, 'version'),
            self::str($row, 'description'),
            self::str($row, 'body'),
            self::str($row, 'status'),
            self::str($row, 'reason'),
            isset($row['principal_id']) && is_int($row['principal_id']) ? $row['principal_id'] : null,
        );
    }

    /** @param array<string, mixed> $row */
    private static function int(array $row, string $key): int {
        return isset($row[$key]) && is_int($row[$key]) ? $row[$key] : throw new UnexpectedValueException('Malformed skill row: ' . $key);
    }

    /** @param array<string, mixed> $row */
    private static function str(array $row, string $key): string {
        return isset($row[$key]) && is_string($row[$key]) ? $row[$key] : throw new UnexpectedValueException('Malformed skill row: ' . $key);
    }
}
