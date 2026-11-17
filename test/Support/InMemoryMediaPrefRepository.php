<?php

declare(strict_types=1);

namespace OpenCCK\Test\Support;

use OpenCCK\Domain\Media\MediaPref;
use OpenCCK\Domain\Repository\MediaPrefRepository;

final class InMemoryMediaPrefRepository implements MediaPrefRepository {
    /** @var array<int, array<string, MediaPref>> */
    public array $prefs = [];

    public function of(int $principalId): array {
        return $this->prefs[$principalId] ?? [];
    }

    public function save(int $principalId, MediaPref $pref): void {
        if ($pref->mode === null && $pref->model === null && $pref->rules === '') {
            unset($this->prefs[$principalId][$pref->kind]);
            return;
        }
        $this->prefs[$principalId][$pref->kind] = $pref;
    }
}
