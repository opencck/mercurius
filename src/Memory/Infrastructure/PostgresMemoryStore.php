<?php

declare(strict_types=1);

namespace OpenCCK\Memory\Infrastructure;

use Amp\Postgres\PostgresLink;
use OpenCCK\Memory\Domain\Fact;
use OpenCCK\Memory\Domain\MemoryStore;
use OpenCCK\Memory\Domain\Proposal;
use Throwable;
use UnexpectedValueException;

/**
 * spec: BRIEF.md#факты — facts with versions in PostgreSQL, keys in a GIN-indexed array
 * ref: references/hermes-plugin-holographic/history.py@4a794f8:L489-L710 (change_fact, end_fact, forget_fact, _erase)
 *      — versions and tombstones; here one database with a scope column instead of a file per scope
 */
final class PostgresMemoryStore implements MemoryStore {
    private const FACT = 'id, scope, category, text, array_to_json(keys)::text AS keys, trust::float8 AS trust, provenance, status, version, extract(epoch FROM updated_at)::bigint AS updated';
    private const PROPOSAL = 'id, kind, fact_id, scope, text, reason, status, principal_id, run_id';

    public function __construct(private readonly PostgresLink $db) {}

    public function addFact(string $scope, string $category, string $text, array $keys, float $trust, string $provenance, ?int $runId): Fact {
        $transaction = $this->db->beginTransaction();
        try {
            $row = $transaction->execute(
                'INSERT INTO facts (scope, category, text, keys, trust, provenance, run_id) VALUES (?, ?, ?, ?::text[], ?, ?, ?) RETURNING ' . self::FACT,
                [$scope, $category, $text, $keys, $trust, $provenance, $runId],
            )->fetchRow() ?? throw new UnexpectedValueException('Fact was not created');
            $fact = self::fact_($row);
            $transaction->execute('INSERT INTO fact_versions (fact_id, version, text, reason) VALUES (?, 1, ?, ?)', [$fact->id, $text, 'добавлен']);
            $transaction->commit();
            return $fact;
        } catch (Throwable $e) {
            if ($transaction->isActive()) {
                $transaction->rollback();
            }
            throw $e;
        }
    }

    public function fact(int $id): ?Fact {
        $row = $this->db->execute('SELECT ' . self::FACT . ' FROM facts WHERE id = ?', [$id])->fetchRow();
        return $row === null ? null : self::fact_($row);
    }

    public function candidates(array $scopes, array $keys, int $limit): array {
        if ($scopes === [] || $keys === []) {
            return [];
        }
        $facts = [];
        foreach ($this->db->execute(
            "SELECT " . self::FACT . " FROM facts WHERE status = 'active' AND scope = ANY(?::text[]) AND keys && ?::text[] ORDER BY updated_at DESC LIMIT ?",
            [$scopes, $keys, $limit],
        ) as $row) {
            $facts[] = self::fact_($row);
        }
        return $facts;
    }

    public function frequencies(array $scopes, array $keys): array {
        $df = ['' => 0];
        if ($scopes === []) {
            return $df;
        }
        $total = $this->db->execute("SELECT count(*)::int AS n FROM facts WHERE status = 'active' AND scope = ANY(?::text[])", [$scopes])->fetchRow();
        $df[''] = self::int($total ?? [], 'n');
        if ($keys === []) {
            return $df;
        }
        foreach ($this->db->execute(
            "SELECT k AS key, count(*)::int AS n FROM facts, unnest(keys) AS k
             WHERE status = 'active' AND scope = ANY(?::text[]) AND k = ANY(?::text[]) GROUP BY k",
            [$scopes, $keys],
        ) as $row) {
            $df[self::str($row, 'key')] = self::int($row, 'n');
        }
        return $df;
    }

    public function recent(array $scopes, int $limit): array {
        if ($scopes === []) {
            return [];
        }
        $facts = [];
        foreach ($this->db->execute("SELECT " . self::FACT . " FROM facts WHERE status = 'active' AND scope = ANY(?::text[]) ORDER BY updated_at DESC, id DESC LIMIT ?", [$scopes, $limit]) as $row) {
            $facts[] = self::fact_($row);
        }
        return $facts;
    }

    public function changeFact(int $id, string $text, array $keys, string $reason): ?Fact {
        $transaction = $this->db->beginTransaction();
        try {
            $row = $transaction->execute(
                'UPDATE facts SET text = ?, keys = ?::text[], version = version + 1, updated_at = now() WHERE id = ? RETURNING ' . self::FACT,
                [$text, $keys, $id],
            )->fetchRow();
            if ($row === null) {
                $transaction->rollback();
                return null;
            }
            $fact = self::fact_($row);
            $transaction->execute('UPDATE fact_versions SET valid_to = now() WHERE fact_id = ? AND valid_to IS NULL', [$id]);
            $transaction->execute('INSERT INTO fact_versions (fact_id, version, text, reason) VALUES (?, ?, ?, ?)', [$id, $fact->version, $text, $reason]);
            $transaction->commit();
            return $fact;
        } catch (Throwable $e) {
            if ($transaction->isActive()) {
                $transaction->rollback();
            }
            throw $e;
        }
    }

    public function endFact(int $id, string $reason): bool {
        $row = $this->db->execute("UPDATE facts SET status = 'ended', updated_at = now() WHERE id = ? AND status = 'active' RETURNING id", [$id])->fetchRow();
        if ($row === null) {
            return false;
        }
        $this->db->execute('UPDATE fact_versions SET valid_to = now(), reason = reason || ?::text WHERE fact_id = ? AND valid_to IS NULL', ['; завершён: ' . $reason, $id]);
        return true;
    }

    public function forgetFact(int $id, string $fingerprint): bool {
        $transaction = $this->db->beginTransaction();
        try {
            // Versions and recalls go with the fact (ON DELETE CASCADE); only the fingerprint stays.
            $row = $transaction->execute('DELETE FROM facts WHERE id = ? RETURNING id', [$id])->fetchRow();
            if ($row === null) {
                $transaction->rollback();
                return false;
            }
            $transaction->execute('INSERT INTO fact_tombstones (hmac) VALUES (?) ON CONFLICT DO NOTHING', [$fingerprint]);
            $transaction->commit();
            return true;
        } catch (Throwable $e) {
            if ($transaction->isActive()) {
                $transaction->rollback();
            }
            throw $e;
        }
    }

    public function forgotten(string $fingerprint): bool {
        return $this->db->execute('SELECT 1 AS found FROM fact_tombstones WHERE hmac = ?', [$fingerprint])->fetchRow() !== null;
    }

    public function propose(string $kind, ?int $factId, string $scope, string $text, string $reason, ?int $principalId, ?int $runId): Proposal {
        $row = $this->db->execute(
            'INSERT INTO memory_proposals (kind, fact_id, scope, text, reason, principal_id, run_id) VALUES (?, ?, ?, ?, ?, ?, ?) RETURNING ' . self::PROPOSAL,
            [$kind, $factId, $scope, $text, $reason, $principalId, $runId],
        )->fetchRow() ?? throw new UnexpectedValueException('Proposal was not created');
        return self::proposal_($row);
    }

    public function proposal(int $id): ?Proposal {
        $row = $this->db->execute('SELECT ' . self::PROPOSAL . ' FROM memory_proposals WHERE id = ?', [$id])->fetchRow();
        return $row === null ? null : self::proposal_($row);
    }

    public function decide(int $id, string $status): ?Proposal {
        $row = $this->db->execute(
            "UPDATE memory_proposals SET status = ?, decided_at = now() WHERE id = ? AND status = 'pending' RETURNING " . self::PROPOSAL,
            [$status, $id],
        )->fetchRow();
        return $row === null ? null : self::proposal_($row);
    }

    public function setCard(int $id, string $chatId, string $messageId): void {
        $this->db->execute('UPDATE memory_proposals SET card_chat_id = ?, card_message_id = ? WHERE id = ?', [$chatId, $messageId, $id]);
    }

    public function pending(): array {
        $proposals = [];
        foreach ($this->db->query("SELECT " . self::PROPOSAL . " FROM memory_proposals WHERE status = 'pending' ORDER BY id") as $row) {
            $proposals[] = self::proposal_($row);
        }
        return $proposals;
    }

    public function core(string $section): string {
        $row = $this->db->execute('SELECT text FROM memory_core WHERE section = ? ORDER BY version DESC LIMIT 1', [$section])->fetchRow();
        return $row === null ? '' : self::str($row, 'text');
    }

    public function setCore(string $section, string $text): void {
        $this->db->execute(
            'INSERT INTO memory_core (section, version, text) SELECT ?, coalesce(max(version), 0) + 1, ? FROM memory_core WHERE section = ?',
            [$section, $text, $section],
        );
    }

    public function markRecalled(int $conversationId, array $factIds): void {
        foreach ($factIds as $id) {
            $this->db->execute('INSERT INTO memory_recalls (conversation_id, fact_id) VALUES (?, ?)', [$conversationId, $id]);
        }
    }

    public function recalled(int $conversationId, int $window): array {
        $ids = [];
        foreach ($this->db->execute('SELECT fact_id FROM memory_recalls WHERE conversation_id = ? ORDER BY id DESC LIMIT ?', [$conversationId, $window]) as $row) {
            $ids[self::int($row, 'fact_id')] = true;
        }
        return array_keys($ids);
    }

    /** @param array<string, mixed> $row */
    private static function fact_(array $row): Fact {
        /** @var mixed $keys */
        $keys = json_decode(self::str($row, 'keys'), true, 4, JSON_THROW_ON_ERROR);
        $list = [];
        if (is_array($keys)) {
            foreach (array_keys($keys) as $i) {
                if (isset($keys[$i]) && is_string($keys[$i])) {
                    $list[] = $keys[$i];
                }
            }
        }
        $trust = isset($row['trust']) && is_float($row['trust']) ? $row['trust'] : 0.0;
        return new Fact(
            self::int($row, 'id'),
            self::str($row, 'scope'),
            self::str($row, 'category'),
            self::str($row, 'text'),
            $list,
            $trust,
            self::str($row, 'provenance'),
            self::str($row, 'status') === 'active',
            self::int($row, 'version'),
            self::int($row, 'updated'),
        );
    }

    /** @param array<string, mixed> $row */
    private static function proposal_(array $row): Proposal {
        return new Proposal(
            self::int($row, 'id'),
            self::str($row, 'kind'),
            isset($row['fact_id']) ? self::int($row, 'fact_id') : null,
            self::str($row, 'scope'),
            self::str($row, 'text'),
            self::str($row, 'reason'),
            self::str($row, 'status'),
            isset($row['principal_id']) ? self::int($row, 'principal_id') : null,
            isset($row['run_id']) ? self::int($row, 'run_id') : null,
        );
    }

    /** @param array<string, mixed> $row */
    private static function int(array $row, string $key): int {
        $value = $row[$key] ?? null;
        if (!is_int($value)) {
            throw new UnexpectedValueException(sprintf('Malformed memory row: %s', $key));
        }
        return $value;
    }

    /** @param array<string, mixed> $row */
    private static function str(array $row, string $key): string {
        $value = $row[$key] ?? null;
        if (!is_string($value)) {
            throw new UnexpectedValueException(sprintf('Malformed memory row: %s', $key));
        }
        return $value;
    }
}
