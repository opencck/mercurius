<?php

declare(strict_types=1);

namespace OpenCCK\Test\Support;

use OpenCCK\Domain\Repository\OwnerCardRepository;

final class InMemoryOwnerCardRepository implements OwnerCardRepository {
    /** @var array<int, list<array{string, string}>> */
    public array $cards = [];

    public function create(array $copies): int {
        $id = count($this->cards) + 1;
        $this->cards[$id] = $copies;
        return $id;
    }

    public function copies(int $card): array {
        return $this->cards[$card] ?? [];
    }

    public function cardOf(string $chatId, string $messageId): ?int {
        foreach ($this->cards as $id => $copies) {
            if (in_array([$chatId, $messageId], $copies, true)) {
                return $id;
            }
        }
        return null;
    }
}
