<?php

declare(strict_types=1);

namespace OpenCCK\App\Skill;

use Amp\Cancellation;
use InvalidArgumentException;

/** Where an ordinary skill of another agent comes from: a GitHub directory, an archive. */
interface SkillSource {
    public function supports(string $url): bool;

    /**
     * @throws InvalidArgumentException when the address has no skill or it breaks a limit
     * @throws \Amp\CancelledException when the caller stops waiting
     */
    public function fetch(string $url, Cancellation $cancellation): SkillBundle;
}
