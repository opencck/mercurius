<?php

declare(strict_types=1);

namespace OpenCCK\Domain\Repository;

/**
 * A participant's own time zone and quiet hours. Null — the owner's defaults; quiet hours '' — none.
 *
 * spec: BRIEF.md#несколько-пользователей
 */
interface ParticipantPrefRepository {
    /** @return array{timezone: ?string, quiet: ?string} */
    public function get(int $principalId): array;

    public function save(int $principalId, ?string $timezone, ?string $quiet): void;
}
