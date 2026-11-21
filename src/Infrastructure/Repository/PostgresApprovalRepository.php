<?php

declare(strict_types=1);

namespace OpenCCK\Infrastructure\Repository;

use Amp\Postgres\PostgresLink;
use DateTimeImmutable;
use DateTimeInterface;
use OpenCCK\Domain\Agent\Approval;
use OpenCCK\Domain\Agent\ApprovalStatus;
use OpenCCK\Domain\Agent\Grant;
use OpenCCK\Domain\Agent\GrantRequest;
use OpenCCK\Domain\Repository\ApprovalRepository;
use UnexpectedValueException;

/**
 * spec: brief/agent-loop.md#подтверждения-и-запреты — the decision is fixed atomically before the card is edited
 * ref: references/hermes-agent/plugins/platforms/telegram/adapter.py@e96221fa:L4347-L4389 (_handle_exec_approval_callback)
 *      — "resolve, then draw"; hermes pops in-memory state, we decide by a conditional UPDATE
 */
final class PostgresApprovalRepository implements ApprovalRepository {
    private const COLUMNS = 'id, run_id, call_id, principal_id, tool, summary, grant_key, status, reason, card_chat_id, card_message_id, extract(epoch FROM created_at)::float8 AS created_epoch, wide_key';
    private const GRANT = 'id, principal_id, key, conversation_id, outside, uses, label, extract(epoch FROM created_at)::bigint AS created';

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

    public function deniedInARow(int $principalId, string $summary, DateTimeImmutable $since): int {
        $denied = 0;
        foreach ($this->db->execute(
            // spec: brief/agent-loop.md#три-отказа-подряд — only a person's refusal counts: one the agent made itself
            //       (the card did not reach the owner, already refused in a row) neither counts nor breaks the streak
            "SELECT status FROM approvals WHERE principal_id = ? AND summary = ? AND created_at >= ?
               AND (status = 'approved' OR (status = 'denied' AND decided_by IS NOT NULL))
             ORDER BY id DESC LIMIT 10",
            [$principalId, $summary, $since->format('Y-m-d H:i:sP')],
        ) as $row) {
            if (($row['status'] ?? null) !== 'denied') {
                break;
            }
            $denied++;
        }
        return $denied;
    }

    public function setWideKey(int $id, string $wideKey): void {
        $this->db->execute('UPDATE approvals SET wide_key = ? WHERE id = ?', [$wideKey, $id]);
    }

    public function hasGrant(int $principalId, string $key): bool {
        return $this->db->execute(
            'SELECT 1 FROM approval_grants WHERE principal_id = ? AND key = ? AND conversation_id IS NULL AND revoked_at IS NULL',
            [$principalId, $key],
        )->fetchRow() !== null;
    }

    public function addGrant(int $principalId, string $key, ?int $conversationId = null, bool $outside = false, ?string $label = null): void {
        $this->db->execute(
            'INSERT INTO approval_grants (principal_id, key, conversation_id, outside, label) VALUES (?, ?, ?, ?, ?)
             ON CONFLICT (principal_id, key, COALESCE(conversation_id, 0)) WHERE revoked_at IS NULL
             DO UPDATE SET outside = approval_grants.outside OR EXCLUDED.outside, label = COALESCE(approval_grants.label, EXCLUDED.label)',
            [$principalId, $key, $conversationId, $outside, $label === null ? null : mb_substr($label, 0, 300)],
        );
    }

    public function grantFor(int $principalId, array $keys, ?int $conversationId, bool $outside): ?Grant {
        if ($keys === []) {
            return null;
        }
        $row = $this->db->execute(
            'SELECT ' . self::GRANT . ' FROM approval_grants
             WHERE principal_id = ? AND key = ANY(?) AND revoked_at IS NULL
               AND (conversation_id IS NULL OR conversation_id = ?) AND (outside OR NOT ?::boolean)
             ORDER BY conversation_id NULLS FIRST, id LIMIT 1',
            [$principalId, $keys, $conversationId, $outside],
        )->fetchRow();
        return $row === null ? null : self::grant($row);
    }

    public function useGrant(int $id): void {
        $this->db->execute('UPDATE approval_grants SET uses = uses + 1, last_used_at = now() WHERE id = ?', [$id]);
    }

    public function grants(?int $principalId = null): array {
        $grants = [];
        foreach ($this->db->execute(
            'SELECT ' . self::GRANT . ' FROM approval_grants WHERE (?::bigint IS NULL OR principal_id = ?) AND revoked_at IS NULL ORDER BY id',
            [$principalId, $principalId],
        ) as $row) {
            $grants[] = self::grant($row);
        }
        return $grants;
    }

    public function revokeGrant(int $id): ?Grant {
        $row = $this->db->execute(
            'UPDATE approval_grants SET revoked_at = now() WHERE id = ? AND revoked_at IS NULL RETURNING ' . self::GRANT,
            [$id],
        )->fetchRow();
        return $row === null ? null : self::grant($row);
    }

    public function revokeGrants(string $prefix): int {
        $count = 0;
        foreach ($this->db->execute(
            'UPDATE approval_grants SET revoked_at = now() WHERE revoked_at IS NULL AND left(key, length(?)) = ? RETURNING id',
            [$prefix, $prefix],
        ) as $row) {
            $count++;
        }
        return $count;
    }

    public function noteNeed(string $area, int $principalId, string $key, string $label): void {
        $item = json_encode([['key' => $key, 'label' => $label]], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
        $this->db->execute(
            "INSERT INTO grant_requests (area, principal_id, items) VALUES (?, ?, ?::jsonb)
             ON CONFLICT (area) WHERE status = 'pending' DO UPDATE SET items = grant_requests.items || EXCLUDED.items
             WHERE NOT grant_requests.items @> jsonb_build_array(jsonb_build_object('key', ?::text))",
            [$area, $principalId, $item, $key],
        );
    }

    public function needs(string $area): ?GrantRequest {
        $row = $this->db->execute("SELECT id, area, principal_id, items::text AS items, status, card_chat_id, card_message_id FROM grant_requests WHERE area = ? AND status = 'pending'", [$area])->fetchRow();
        return $row === null ? null : self::request($row);
    }

    public function findNeeds(int $id): ?GrantRequest {
        $row = $this->db->execute('SELECT id, area, principal_id, items::text AS items, status, card_chat_id, card_message_id FROM grant_requests WHERE id = ?', [$id])->fetchRow();
        return $row === null ? null : self::request($row);
    }

    public function setNeedsCard(int $id, string $chatId, string $messageId): void {
        $this->db->execute('UPDATE grant_requests SET card_chat_id = ?, card_message_id = ? WHERE id = ?', [$chatId, $messageId, $id]);
    }

    public function closeNeeds(int $id, string $status): ?GrantRequest {
        $row = $this->db->execute(
            "UPDATE grant_requests SET status = ? WHERE id = ? AND status = 'pending' RETURNING id, area, principal_id, items::text AS items, status, card_chat_id, card_message_id",
            [$status, $id],
        )->fetchRow();
        return $row === null ? null : self::request($row);
    }

    /** @param array<string, mixed> $row */
    private static function request(array $row): GrantRequest {
        $items = [];
        /** @var mixed $decoded */
        $decoded = json_decode(self::string($row, 'items'), true);
        /** @var mixed $item */
        foreach (is_array($decoded) ? $decoded : [] as $item) {
            if (is_array($item) && isset($item['key'], $item['label']) && is_string($item['key']) && is_string($item['label'])) {
                $items[] = ['key' => $item['key'], 'label' => $item['label']];
            }
        }
        return new GrantRequest(self::int($row, 'id'), self::string($row, 'area'), self::int($row, 'principal_id'), $items, self::string($row, 'status'), self::nullable($row, 'card_chat_id'), self::nullable($row, 'card_message_id'));
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
            isset($row['created_epoch']) && is_float($row['created_epoch'])
                ? (\DateTimeImmutable::createFromFormat('U.u', sprintf('%.6F', $row['created_epoch'])) ?: null)
                : null,
            self::nullable($row, 'wide_key'),
        );
    }

    /** @param array<string, mixed> $row */
    private static function grant(array $row): Grant {
        return new Grant(self::int($row, 'id'), self::int($row, 'principal_id'), self::string($row, 'key'), isset($row['conversation_id']) && is_int($row['conversation_id']) ? $row['conversation_id'] : null, ($row['outside'] ?? false) === true, isset($row['uses']) && is_int($row['uses']) ? $row['uses'] : 0, self::nullable($row, 'label'), isset($row['created']) && is_int($row['created']) ? (new \DateTimeImmutable())->setTimestamp($row['created']) : null);
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
