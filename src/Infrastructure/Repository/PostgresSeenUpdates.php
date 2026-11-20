<?php

declare(strict_types=1);

namespace OpenCCK\Infrastructure\Repository;

use Amp\Postgres\PostgresLink;

/**
 * Keys of the messenger's updates already taken, kept a day, so a redelivery after a restart is not run twice.
 *
 * spec: brief/channels.md#канал-необязательные-возможности-и-разрезанные-тексты
 * ref: references/hermes-agent/plugins/platforms/telegram/update_admission.py@e96221fa:L1-L8, L35 (RECEIPT_TTL_SECONDS) —
 *      completed update ids survive a restart for 24 hours; hermes keeps them in a file per bot, here in the database
 */
final class PostgresSeenUpdates {
    public function __construct(private readonly PostgresLink $db) {}

    /** Records the key; true when it was not there before. */
    public function fresh(string $key): bool {
        $row = $this->db->execute('INSERT INTO seen_updates (key) VALUES (?) ON CONFLICT (key) DO NOTHING RETURNING key', [$key])->fetchRow();
        return $row !== null;
    }

    /** Forgets keys older than a day; how many went. */
    public function prune(): int {
        return $this->db->execute("DELETE FROM seen_updates WHERE seen_at < now() - interval '1 day'")->getRowCount() ?? 0;
    }
}
