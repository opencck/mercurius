<?php

declare(strict_types=1);

namespace OpenCCK\App\Agent\Media;

/**
 * What the agent gets about the files of a message: a block for the message text (empty — nothing came) and whether
 * it holds data from outside (then the run is marked outside: saved grants are not used in it).
 *
 * spec: brief/internet.md#приём
 */
final class IntakeResult {
    public function __construct(
        public readonly string $text,
        public readonly bool $outside,
    ) {}
}
