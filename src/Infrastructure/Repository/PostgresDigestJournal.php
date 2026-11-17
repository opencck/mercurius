<?php

declare(strict_types=1);

namespace OpenCCK\Infrastructure\Repository;

use Amp\Postgres\PostgresLink;
use OpenCCK\Domain\Repository\DigestJournal;

final class PostgresDigestJournal implements DigestJournal {
    public function __construct(private readonly PostgresLink $db) {}

    public function claim(string $day): bool {
        return $this->db->execute('INSERT INTO digests (day) VALUES (?::date) ON CONFLICT DO NOTHING RETURNING day', [$day])->fetchRow() !== null;
    }
}
