<?php

declare(strict_types=1);

namespace OpenCCK\Infrastructure\Repository;

use Amp\Postgres\PostgresLink;
use DateTimeImmutable;
use DateTimeInterface;
use OpenCCK\Domain\Agent\Approval;
use OpenCCK\Domain\Agent\ApprovalStatus;
use OpenCCK\Domain\Agent\Grant;
use OpenCCK\Domain\Repository\ApprovalRepository;
use UnexpectedValueException;

/**
 * spec: BRIEF.md#подтверждения-и-запреты — the decision is fixed atomically before the card is edited
 * ref: references/hermes-agent/plugins/platforms/telegram/adapter.py@e96221f:L4347-L4389 (_handle_exec_approval_callback)
 *      — "resolve, then draw"; hermes pops in-memory state, we decide by a conditional UPDATE
 */
final class PostgresApprovalRepository implements ApprovalRepository {
    private const COLUMNS = 'id, run_id, call_id, principal_id, tool, summary, grant_key, status, reason, card_chat_id, card_message_id';

    public function __construct(private readonly PostgresLink $db) {}

    public function create(int $runId, string $callId, int $principalId, string $tool, string $summary, ?string $grantKey, DateTimeImmutable $expiresAt): Approval {
        $row = $this->db->execute(
            "INSERT INTO approvals (run_id, call_id, principal_id, tool, summary, grant_key, status, expires_at)
             VALUES (?, ?, ?, ?, ?, ?, 'pending', ?) RETURNING " . self::COLUMNS,
            [$runId, $callId, $principalId, $tool, $summary, $grantKey, $expiresAt->format(DateTimeInterface::ATOM)],
        )->fetchRow();
        return self::approval($row ?? []);
    }

    public function find(int $id): ?Approval {
        $row = $this->db->execute('SELECT ' . self::COLUMNS . ' FROM approvals WHERE id = ?', [$id])->fetchRow();
        return $row === null ? null : self::approval($row);
    }

    public function forCall(int $runId, string $callId): ?Approval {
        $row = $this->db->execute('SELECT ' . self::COLUMNS . ' FROM approvals WHERE run_id = ? AND call_id = ?', [$runId, $callId])->fetchRow();
        return $row === null ? null : self::approval($row);
    }

    public function decide(int $id, ApprovalStatus $status, ?string $reason, ?int $decidedBy): ?Approval {
        $row = $this->db->execute(
            "UPDATE approvals SET status = ?, reason = ?, decided_by = ?, decided_at = now()
             WHERE id = ? AND status = 'pending' AND (?::text = 'expired' OR expires_at > now()) RETURNING " . self::COLUMNS,
            [$status->value, $reason, $decidedBy, $id, $status->value],
        )->fetchRow();
        return $row === null ? null : self::approval($row);
    }

    public function due(DateTimeImmutable $now): array {
        $approvals = [];
        foreach ($this->db->execute(
            "SELECT " . self::COLUMNS . " FROM approvals WHERE status = 'pending' AND expires_at <= ? ORDER BY id",
            [$now->format(DateTimeInterface::ATOM)],
        ) as $row) {
            $approvals[] = self::approval($row);
        }
        return $approvals;
    }

    public function pendingForRun(int $runId): ?Approval {
        $row = $this->db->execute(
            'SELECT ' . self::COLUMNS . " FROM approvals WHERE run_id = ? AND status = 'pending' ORDER BY id DESC LIMIT 1",
            [$runId],
        )->fetchRow();
        return $row === null ? null : self::approval($row);
    }

    public function setCard(int $id, string $chatId, string $messageId): void {
        $this->db->execute('UPDATE approvals SET card_chat_id = ?, card_message_id = ? WHERE id = ?', [$chatId, $messageId, $id]);
    }

    public function hasGrant(int $principalId, string $key): bool {
        return $this->db->execute(
            'SELECT 1 FROM approval_grants WHERE principal_id = ? AND key = ? AND revoked_at IS NULL',
            [$principalId, $key],
        )->fetchRow() !== null;
    }

    public function addGrant(int $principalId, string $key): void {
        $this->db->execute(
            'INSERT INTO approval_grants (principal_id, key) VALUES (?, ?) ON CONFLICT (principal_id, key) WHERE revoked_at IS NULL DO NOTHING',
            [$principalId, $key],
        );
    }

    public function grants(?int $principalId = null): array {
        $grants = [];
        foreach ($this->db->execute(
            'SELECT id, principal_id, key FROM approval_grants WHERE (?::bigint IS NULL OR principal_id = ?) AND revoked_at IS NULL ORDER BY id',
            [$principalId, $principalId],
        ) as $row) {
            $grants[] = self::grant($row);
        }
        return $grants;
    }

    public function revokeGrant(int $id): ?Grant {
        $row = $this->db->execute(
            'UPDATE approval_grants SET revoked_at = now() WHERE id = ? AND revoked_at IS NULL RETURNING id, principal_id, key',
            [$id],
        )->fetchRow();
        return $row === null ? null : self::grant($row);
    }

    /** @param array<string, mixed> $row */
    private static function approval(array $row): Approval {
        return new Approval(
            self::int($row, 'id'),
            self::int($row, 'run_id'),
            self::string($row, 'call_id'),
            self::int($row, 'principal_id'),
            self::string($row, 'tool'),
            self::string($row, 'summary'),
            self::nullable($row, 'grant_key'),
            ApprovalStatus::from(self::string($row, 'status')),
            self::nullable($row, 'reason'),
            self::nullable($row, 'card_chat_id'),
            self::nullable($row, 'card_message_id'),
        );
    }

    /** @param array<string, mixed> $row */
    private static function grant(array $row): Grant {
        return new Grant(self::int($row, 'id'), self::int($row, 'principal_id'), self::string($row, 'key'));
    }

    /** @param array<string, mixed> $row */
    private static function int(array $row, string $key): int {
        if (!isset($row[$key]) || !is_int($row[$key])) {
            throw new UnexpectedValueException(sprintf('Malformed approvals row: %s', $key));
        }
        return $row[$key];
    }

    /** @param array<string, mixed> $row */
    private static function string(array $row, string $key): string {
        if (!isset($row[$key]) || !is_string($row[$key])) {
            throw new UnexpectedValueException(sprintf('Malformed approvals row: %s', $key));
        }
        return $row[$key];
    }

    /** @param array<string, mixed> $row */
    private static function nullable(array $row, string $key): ?string {
        return isset($row[$key]) && is_string($row[$key]) ? $row[$key] : null;
    }
}
