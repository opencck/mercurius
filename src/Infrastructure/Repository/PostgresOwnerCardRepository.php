<?php

declare(strict_types=1);

namespace OpenCCK\Infrastructure\Repository;

use Amp\Postgres\PostgresLink;
use OpenCCK\Domain\Repository\OwnerCardRepository;
use UnexpectedValueException;

final class PostgresOwnerCardRepository implements OwnerCardRepository {
    public function __construct(private readonly PostgresLink $db) {}

    public function create(array $copies): int {
        $tx = $this->db->beginTransaction();
        try {
            $id = $tx->query('INSERT INTO owner_cards DEFAULT VALUES RETURNING id')->fetchRow()['id'] ?? null;
            if (!is_int($id) || $id < 1) {
                throw new UnexpectedValueException('Owner card was not created');
            }
            foreach ($copies as [$chat, $message]) {
                $tx->execute('INSERT INTO owner_card_copies (card_id, chat_id, message_id) VALUES (?, ?, ?) ON CONFLICT DO NOTHING', [$id, $chat, $message]);
            }
            $tx->commit();
            return $id;
        } finally {
            if ($tx->isActive()) {
                $tx->rollback();
            }
        }
    }

    public function copies(int $card): array {
        $found = [];
        foreach ($this->db->execute('SELECT chat_id, message_id FROM owner_card_copies WHERE card_id = ? ORDER BY chat_id', [$card]) as $row) {
            $chat = $row['chat_id'] ?? null;
            $message = $row['message_id'] ?? null;
            if (is_string($chat) && is_string($message)) {
                $found[] = [$chat, $message];
            }
        }
        return $found;
    }

    public function cardOf(string $chatId, string $messageId): ?int {
        $id = $this->db->execute('SELECT card_id FROM owner_card_copies WHERE chat_id = ? AND message_id = ?', [$chatId, $messageId])->fetchRow()['card_id'] ?? null;
        return is_int($id) ? $id : null;
    }
}
