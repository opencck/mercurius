<?php

declare(strict_types=1);

namespace OpenCCK\Domain\Repository;

use OpenCCK\Domain\Media\MediaPref;

/** spec: brief/internet.md#входящие-вложения — how each participant wants each kind of file handled */
interface MediaPrefRepository {
    /** @return array<string, MediaPref> by kind; a kind without a row is absent */
    public function of(int $principalId): array;

    /** Keeps the participant's way for the kind; all fields empty — the row goes. */
    public function save(int $principalId, MediaPref $pref): void;
}
