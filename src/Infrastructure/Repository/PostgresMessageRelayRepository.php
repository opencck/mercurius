<?php

declare(strict_types=1);

namespace OpenCCK\Infrastructure\Repository;

use Amp\Postgres\PostgresLink;
use DateTimeImmutable;
use OpenCCK\Domain\Relay\MessageRelay;
use OpenCCK\Domain\Repository\MessageRelayRepository;

final class PostgresMessageRelayRepository implements MessageRelayRepository {
    public function __construct(private readonly PostgresLink $db) {}

    public function create(string $channel, string $recipientChatId, int $recipientPrincipalId, int $originConversationId, int $originPrincipalId, ?int $originRunId, string $question): int {
        /** @var array<string, mixed>|null $row */
        $row = $this->db->execute(
            'INSERT INTO message_relays (channel, recipient_chat_id, recipient_principal_id, origin_conversation_id, origin_principal_id, origin_run_id, question)
             VALUES (?, ?, ?, ?, ?, ?, ?) RETURNING id',
            [$channel, $recipientChatId, $recipientPrincipalId, $originConversationId, $originPrincipalId, $originRunId, $question],
        )->fetchRow();
        return $row === null ? 0 : self::int($row, 'id');
    }

    public function takeOpen(string $channel, string $recipientChatId, DateTimeImmutable $since): ?MessageRelay {
        // one statement: two messages arriving at once cannot take the same question
        /** @var array<string, mixed>|null $row */
        $row = $this->db->execute(
            'UPDATE message_relays SET answered_at = now()
             WHERE id = (SELECT id FROM message_relays WHERE channel = ? AND recipient_chat_id = ? AND answered_at IS NULL AND created_at >= ?
                         ORDER BY id LIMIT 1 FOR UPDATE SKIP LOCKED)
             RETURNING id, channel, recipient_chat_id, recipient_principal_id, origin_conversation_id, origin_principal_id, origin_run_id, question',
            [$channel, $recipientChatId, $since->format('Y-m-d H:i:sP')],
        )->fetchRow();
        if ($row === null) {
            return null;
        }
        return new MessageRelay(
            self::int($row, 'id'),
            self::str($row, 'channel'),
            self::str($row, 'recipient_chat_id'),
            self::int($row, 'recipient_principal_id'),
            self::int($row, 'origin_conversation_id'),
            self::int($row, 'origin_principal_id'),
            isset($row['origin_run_id']) ? self::int($row, 'origin_run_id') : null,
            self::str($row, 'question'),
        );
    }

    /** @param array<string, mixed> $row */
    private static function int(array $row, string $key): int {
        /** @var mixed $value */
        $value = $row[$key] ?? null;
        return is_int($value) ? $value : (is_string($value) && is_numeric($value) ? (int) $value : 0);
    }

    /** @param array<string, mixed> $row */
    private static function str(array $row, string $key): string {
        /** @var mixed $value */
        $value = $row[$key] ?? null;
        return is_string($value) ? $value : '';
    }
}
