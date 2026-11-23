<?php

declare(strict_types=1);

namespace OpenCCK\Test\Support;

use OpenCCK\Domain\Repository\ContextLimitRepository;

final class InMemoryContextLimitRepository implements ContextLimitRepository {
    /** @var array<string, array{?int, ?int}> */
    public array $limits = [];

    public function get(string $kind, int $id): array {
        return $this->limits[$kind . ':' . $id] ?? [null, null];
    }

    public function save(string $kind, int $id, ?int $tokens, ?int $percent): void {
        if ($tokens === null && $percent === null) {
            unset($this->limits[$kind . ':' . $id]);
            return;
        }
        $this->limits[$kind . ':' . $id] = [$tokens, $percent];
    }
}
