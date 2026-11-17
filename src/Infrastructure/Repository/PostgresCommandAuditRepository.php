<?php

declare(strict_types=1);

namespace OpenCCK\Infrastructure\Repository;

use Amp\Postgres\PostgresLink;
use OpenCCK\Domain\Repository\CommandAuditRepository;
use OpenCCK\Domain\Server\AuditChain;
use Throwable;
use UnexpectedValueException;

final class PostgresCommandAuditRepository implements CommandAuditRepository {
    public function __construct(private readonly PostgresLink $db) {}

    public function append(array $record): int {
        $transaction = $this->db->beginTransaction();
        try {
            // One writer at a time: each record is linked to the last one.
            $transaction->query("SELECT pg_advisory_xact_lock(hashtext('command_audit'))");
            $last = $transaction->query('SELECT hash FROM command_audit ORDER BY id DESC LIMIT 1')->fetchRow();
            $previous = $last === null ? AuditChain::GENESIS : self::str($last, 'hash', AuditChain::GENESIS);
            $at = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.uP');
            $full = ['at' => $at] + $record;
            $hash = AuditChain::hash($full, $previous);
            $row = $transaction->execute(
                'INSERT INTO command_audit (at, principal_id, run_id, server, command, class, event, exit_code, duration_ms, error, prev_hash, hash)
                 VALUES (?::timestamptz, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?) RETURNING id',
                [$at, $record['principal'], $record['run'], $record['server'], $record['command'], $record['class'], $record['event'], $record['exit'], $record['ms'], $record['error'], $previous, $hash],
            )->fetchRow();
            $transaction->commit();
            $id = $row['id'] ?? null;
            return is_int($id) ? $id : throw new UnexpectedValueException('Audit record was not written');
        } catch (Throwable $e) {
            if ($transaction->isActive()) {
                $transaction->rollback();
            }
            throw $e;
        }
    }

    public function verify(): ?int {
        $previous = AuditChain::GENESIS;
        foreach ($this->db->query(
            "SELECT id, to_char(at AT TIME ZONE 'UTC', 'YYYY-MM-DD\"T\"HH24:MI:SS.US') || '+00:00' AS at, principal_id, run_id, server, command, class, event, exit_code, duration_ms, error, prev_hash, hash
             FROM command_audit ORDER BY id",
        ) as $row) {
            $id = self::int($row, 'id') ?? 0;
            $record = [
                'at' => self::str($row, 'at'),
                'principal' => self::int($row, 'principal_id'),
                'run' => self::int($row, 'run_id'),
                'server' => self::str($row, 'server'),
                'command' => self::str($row, 'command'),
                'class' => self::str($row, 'class'),
                'event' => self::str($row, 'event'),
                'exit' => self::int($row, 'exit_code'),
                'ms' => self::int($row, 'duration_ms'),
                'error' => isset($row['error']) && is_string($row['error']) ? $row['error'] : null,
            ];
            $hash = self::str($row, 'hash');
            if (self::str($row, 'prev_hash') !== $previous || $hash !== AuditChain::hash($record, $previous)) {
                return $id;
            }
            $previous = $hash;
        }
        return null;
    }

    public function recent(int $limit): array {
        $found = [];
        foreach ($this->db->execute(
            "SELECT id, to_char(at AT TIME ZONE 'UTC', 'YYYY-MM-DD HH24:MI') AS at, server, command, class, event, exit_code FROM command_audit ORDER BY id DESC LIMIT ?",
            [$limit],
        ) as $row) {
            $found[] = [
                'id' => self::int($row, 'id') ?? 0,
                'at' => self::str($row, 'at'),
                'server' => self::str($row, 'server'),
                'command' => self::str($row, 'command'),
                'class' => self::str($row, 'class'),
                'event' => self::str($row, 'event'),
                'exit' => self::int($row, 'exit_code'),
            ];
        }
        return $found;
    }

    /** @param array<string, mixed> $row */
    private static function str(array $row, string $key, string $default = ''): string {
        return isset($row[$key]) && is_string($row[$key]) ? $row[$key] : $default;
    }

    /** @param array<string, mixed> $row */
    private static function int(array $row, string $key): ?int {
        return isset($row[$key]) && is_int($row[$key]) ? $row[$key] : null;
    }
}
