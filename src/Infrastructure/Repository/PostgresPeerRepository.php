<?php

declare(strict_types=1);

namespace OpenCCK\Infrastructure\Repository;

use Amp\Postgres\PostgresLink;
use DateTimeImmutable;
use OpenCCK\Domain\Peer\A2aTask;
use OpenCCK\Domain\Peer\OutgoingTask;
use OpenCCK\Domain\Peer\Peer;
use OpenCCK\Domain\Repository\PeerRepository;
use UnexpectedValueException;

final class PostgresPeerRepository implements PeerRepository {
    private const TASK = 'id, principal_id, context_id, message_id, state, run_id, result, depth, path::text AS path';
    private const OUT = 'id, peer, remote_id, principal_id, channel, chat_id, task, state, result';
    private const OPEN = "('TASK_STATE_SUBMITTED', 'TASK_STATE_WORKING')";

    public function __construct(private readonly PostgresLink $db) {}

    public function allowKey(int $principalId, string $name, string $fingerprint): void {
        $this->db->execute(
            'INSERT INTO agent_keys (principal_id, name, fingerprint) VALUES (?, ?, ?)
             ON CONFLICT (principal_id) DO UPDATE SET fingerprint = excluded.fingerprint, created_at = now(), revoked_at = NULL',
            [$principalId, $name, $fingerprint],
        );
    }

    public function revokeKey(string $name): bool {
        return $this->db->execute('UPDATE agent_keys SET revoked_at = now() WHERE name = ? AND revoked_at IS NULL RETURNING name', [$name])->fetchRow() !== null;
    }

    public function principalByFingerprint(string $fingerprint): ?int {
        $row = $this->db->execute('SELECT principal_id FROM agent_keys WHERE fingerprint = ? AND revoked_at IS NULL', [$fingerprint])->fetchRow();
        return isset($row['principal_id']) && is_int($row['principal_id']) ? $row['principal_id'] : null;
    }

    public function keys(): array {
        $found = [];
        foreach ($this->db->query('SELECT name, fingerprint, principal_id, revoked_at IS NOT NULL AS revoked FROM agent_keys ORDER BY name') as $row) {
            if (isset($row['name'], $row['fingerprint'], $row['principal_id']) && is_string($row['name']) && is_string($row['fingerprint']) && is_int($row['principal_id'])) {
                $found[] = ['name' => $row['name'], 'fingerprint' => $row['fingerprint'], 'principal' => $row['principal_id'], 'revoked' => ($row['revoked'] ?? false) === true];
            }
        }
        return $found;
    }

    public function useNonce(string $nonce): bool {
        $this->db->execute("DELETE FROM a2a_nonces WHERE seen_at < now() - interval '15 minutes'");
        return $this->db->execute('INSERT INTO a2a_nonces (nonce) VALUES (?) ON CONFLICT DO NOTHING RETURNING nonce', [$nonce])->fetchRow() !== null;
    }

    public function task(string $id): ?A2aTask {
        $row = $this->db->execute('SELECT ' . self::TASK . ' FROM a2a_tasks WHERE id = ?', [$id])->fetchRow();
        return $row === null ? null : self::taskRow($row);
    }

    public function taskByMessage(int $principalId, string $messageId): ?A2aTask {
        $row = $this->db->execute('SELECT ' . self::TASK . ' FROM a2a_tasks WHERE principal_id = ? AND message_id = ?', [$principalId, $messageId])->fetchRow();
        return $row === null ? null : self::taskRow($row);
    }

    public function createTask(string $id, int $principalId, string $contextId, string $messageId, string $state, int $depth = 0, array $path = []): bool {
        return $this->db->execute(
            'INSERT INTO a2a_tasks (id, principal_id, context_id, message_id, state, depth, path) VALUES (?, ?, ?, ?, ?, ?, ?::jsonb) ON CONFLICT DO NOTHING RETURNING id',
            [$id, $principalId, $contextId, $messageId, $state, $depth, json_encode($path, JSON_THROW_ON_ERROR)],
        )->fetchRow() !== null;
    }

    public function taskByRun(int $runId): ?A2aTask {
        $row = $this->db->execute('SELECT ' . self::TASK . ' FROM a2a_tasks WHERE run_id = ? ORDER BY created_at DESC LIMIT 1', [$runId])->fetchRow();
        return $row === null ? null : self::taskRow($row);
    }

    public function updateTask(string $id, string $state, ?int $runId, ?string $result): void {
        $this->db->execute(
            'UPDATE a2a_tasks SET state = ?, run_id = coalesce(?, run_id), result = coalesce(?, result), updated_at = now() WHERE id = ?',
            [$state, $runId, $result, $id],
        );
    }

    public function contextTurns(int $principalId, string $contextId): int {
        $row = $this->db->execute('SELECT count(*) AS n FROM a2a_tasks WHERE principal_id = ? AND context_id = ?', [$principalId, $contextId])->fetchRow();
        return isset($row['n']) && is_int($row['n']) ? $row['n'] : 0;
    }

    public function stale(DateTimeImmutable $before): array {
        $found = [];
        foreach ($this->db->execute('SELECT ' . self::TASK . ' FROM a2a_tasks WHERE state IN ' . self::OPEN . ' AND updated_at < to_timestamp(?)', [$before->getTimestamp()]) as $row) {
            $found[] = self::taskRow($row);
        }
        return $found;
    }

    /** @param array<array-key, mixed>|null $card */
    public function addPeer(string $name, string $url, string $secret, string $publicKey, ?array $card, string $identity = ''): void {
        $this->db->execute(
            'INSERT INTO peers (name, url, secret, public_key, card, identity) VALUES (?, ?, ?, ?, ?::jsonb, ?)
             ON CONFLICT (name) DO UPDATE SET url = excluded.url, secret = excluded.secret, public_key = excluded.public_key, card = excluded.card, identity = excluded.identity',
            [$name, $url, $secret, $publicKey, $card === null ? null : json_encode($card, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), $identity],
        );
    }

    public function setIdentity(string $name, string $identity): bool {
        return $this->db->execute('UPDATE peers SET identity = ? WHERE name = ? RETURNING name', [$identity, $name])->fetchRow() !== null;
    }

    public function peer(string $name): ?Peer {
        $row = $this->db->execute('SELECT name, url, secret, public_key, card::text AS card, identity FROM peers WHERE name = ?', [$name])->fetchRow();
        return $row === null ? null : self::peerRow($row);
    }

    public function peers(): array {
        $found = [];
        foreach ($this->db->query('SELECT name, url, secret, public_key, card::text AS card, identity FROM peers ORDER BY name') as $row) {
            $found[] = self::peerRow($row);
        }
        return $found;
    }

    public function removePeer(string $name): bool {
        return $this->db->execute('DELETE FROM peers WHERE name = ? RETURNING name', [$name])->fetchRow() !== null;
    }

    public function addOutgoing(string $peer, string $remoteId, int $principalId, string $channel, string $chatId, string $task, string $state): OutgoingTask {
        $row = $this->db->execute(
            'INSERT INTO a2a_outgoing (peer, remote_id, principal_id, channel, chat_id, task, state) VALUES (?, ?, ?, ?, ?, ?, ?) RETURNING ' . self::OUT,
            [$peer, $remoteId, $principalId, $channel, $chatId, $task, $state],
        )->fetchRow();
        return self::outRow($row ?? throw new UnexpectedValueException('Outgoing task was not created'));
    }

    public function openOutgoing(int $limit): array {
        $found = [];
        foreach ($this->db->execute('SELECT ' . self::OUT . ' FROM a2a_outgoing WHERE state IN ' . self::OPEN . ' ORDER BY id LIMIT ?', [$limit]) as $row) {
            $found[] = self::outRow($row);
        }
        return $found;
    }

    public function finishOutgoing(int $id, string $state, ?string $result): void {
        $this->db->execute('UPDATE a2a_outgoing SET state = ?, result = ?, updated_at = now() WHERE id = ?', [$state, $result, $id]);
    }

    /** @param array<string, mixed> $row */
    private static function taskRow(array $row): A2aTask {
        return new A2aTask(
            self::str($row, 'id'),
            self::int($row, 'principal_id'),
            self::str($row, 'context_id'),
            self::str($row, 'message_id'),
            self::str($row, 'state'),
            isset($row['run_id']) && is_int($row['run_id']) ? $row['run_id'] : null,
            isset($row['result']) && is_string($row['result']) ? $row['result'] : null,
            isset($row['depth']) && is_int($row['depth']) ? $row['depth'] : 0,
            self::path($row),
        );
    }

    /**
     * @param array<string, mixed> $row
     * @return list<string>
     */
    private static function path(array $row): array {
        if (!isset($row['path']) || !is_string($row['path'])) {
            return [];
        }
        /** @var mixed $decoded */
        $decoded = json_decode($row['path'], true, 4, JSON_THROW_ON_ERROR);
        $path = [];
        /** @var mixed $item */
        foreach (is_array($decoded) ? $decoded : [] as $item) {
            if (is_string($item)) {
                $path[] = $item;
            }
        }
        return $path;
    }

    /** @param array<string, mixed> $row */
    private static function peerRow(array $row): Peer {
        $card = null;
        if (isset($row['card']) && is_string($row['card'])) {
            /** @var mixed $decoded */
            $decoded = json_decode($row['card'], true, 32, JSON_THROW_ON_ERROR);
            $card = is_array($decoded) ? $decoded : null;
        }
        return new Peer(self::str($row, 'name'), self::str($row, 'url'), self::str($row, 'secret'), self::str($row, 'public_key'), $card, self::str($row, 'identity'));
    }

    /** @param array<string, mixed> $row */
    private static function outRow(array $row): OutgoingTask {
        return new OutgoingTask(
            self::int($row, 'id'),
            self::str($row, 'peer'),
            self::str($row, 'remote_id'),
            self::int($row, 'principal_id'),
            self::str($row, 'channel'),
            self::str($row, 'chat_id'),
            self::str($row, 'task'),
            self::str($row, 'state'),
            isset($row['result']) && is_string($row['result']) ? $row['result'] : null,
        );
    }

    /** @param array<string, mixed> $row */
    private static function str(array $row, string $key): string {
        return isset($row[$key]) && is_string($row[$key]) ? $row[$key] : throw new UnexpectedValueException('Malformed peer row: ' . $key);
    }

    /** @param array<string, mixed> $row */
    private static function int(array $row, string $key): int {
        return isset($row[$key]) && is_int($row[$key]) ? $row[$key] : throw new UnexpectedValueException('Malformed peer row: ' . $key);
    }
}
