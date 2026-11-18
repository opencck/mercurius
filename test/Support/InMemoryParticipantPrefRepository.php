<?php

declare(strict_types=1);

namespace OpenCCK\Test\Support;

use OpenCCK\Domain\Repository\ParticipantPrefRepository;

final class InMemoryParticipantPrefRepository implements ParticipantPrefRepository {
    /** @var array<int, array{timezone: ?string, quiet: ?string}> */
    private array $prefs = [];

    public function get(int $principalId): array {
        return $this->prefs[$principalId] ?? ['timezone' => null, 'quiet' => null];
    }

    public function save(int $principalId, ?string $timezone, ?string $quiet): void {
        $this->prefs[$principalId] = ['timezone' => $timezone, 'quiet' => $quiet];
    }
}
