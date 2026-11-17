<?php

declare(strict_types=1);

namespace OpenCCK\Test\Support;

use OpenCCK\Domain\Agent\Delivery;
use OpenCCK\Domain\Repository\DeliveryRepository;

final class InMemoryDeliveryRepository implements DeliveryRepository {
    /** @var array<int, Delivery> */
    private array $deliveries = [];

    public function open(?int $runId, string $channel, string $chatId, ?string $threadId, ?string $replyTo, string $text, string $parseMode, ?int $createdAt = null): int {
        $id = count($this->deliveries) + 1;
        $this->deliveries[$id] = new Delivery($id, $runId, $channel, $chatId, $threadId, $replyTo, $text, $parseMode, 0, Delivery::PENDING, 0, $createdAt ?? time());
        return $id;
    }

    public function attempting(int $id): void {
        $this->with($id, status: Delivery::ATTEMPTING);
    }

    public function progress(int $id, int $sent): void {
        $this->with($id, sent: $sent);
    }

    public function delivered(int $id): void {
        $this->with($id, status: Delivery::DELIVERED);
    }

    public function failed(int $id, string $error): void {
        $d = $this->deliveries[$id] ?? null;
        if ($d !== null) {
            $this->with($id, status: Delivery::FAILED, attempts: $d->attempts + 1);
        }
    }

    public function abandon(int $id): void {
        $this->with($id, status: Delivery::ABANDONED);
    }

    public function undelivered(): array {
        return array_values(array_filter($this->deliveries, static fn(Delivery $d): bool => in_array($d->status, [Delivery::PENDING, Delivery::ATTEMPTING, Delivery::FAILED], true)));
    }

    /** @return list<Delivery> */
    public function all(): array {
        return array_values($this->deliveries);
    }

    private function with(int $id, ?string $status = null, ?int $sent = null, ?int $attempts = null): void {
        $d = $this->deliveries[$id] ?? null;
        if ($d === null) {
            return;
        }
        $this->deliveries[$id] = new Delivery($d->id, $d->runId, $d->channel, $d->chatId, $d->threadId, $d->replyTo, $d->text, $d->parseMode, $sent ?? $d->sent, $status ?? $d->status, $attempts ?? $d->attempts, $d->createdAt);
    }
}
