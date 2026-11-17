<?php

declare(strict_types=1);

namespace OpenCCK\Test\Support;

use OpenCCK\Domain\Repository\DigestJournal;

final class InMemoryDigestJournal implements DigestJournal {
    /** @var array<string, true> */
    public array $days = [];

    public function claim(string $day): bool {
        if (isset($this->days[$day])) {
            return false;
        }
        $this->days[$day] = true;
        return true;
    }
}
