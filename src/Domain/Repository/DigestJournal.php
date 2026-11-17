<?php

declare(strict_types=1);

namespace OpenCCK\Domain\Repository;

/**
 * spec: BRIEF.md#планировщик-и-проекты — one summary a day, claimed before it is sent
 */
interface DigestJournal {
    /** False — the summary of this day (Y-m-d, the owner's zone) was taken before. */
    public function claim(string $day): bool;
}
