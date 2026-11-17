<?php

declare(strict_types=1);

namespace OpenCCK\Domain\Repository;

use OpenCCK\Domain\Agent\Delivery;

/** Obligations to deliver final answers. Every call is best effort: a failure here never blocks a send. */
interface DeliveryRepository {
    /** @param int|null $createdAt unix time; null — now */
    public function open(?int $runId, string $channel, string $chatId, ?string $threadId, ?string $replyTo, string $text, string $parseMode, ?int $createdAt = null): int;

    public function attempting(int $id): void;

    /** $sent parts of the answer are delivered */
    public function progress(int $id, int $sent): void;

    public function delivered(int $id): void;

    /** A send was refused: one more attempt is spent. */
    public function failed(int $id, string $error): void;

    public function abandon(int $id): void;

    /** @return list<Delivery> pending, attempting and failed ones, oldest first */
    public function undelivered(): array;
}
