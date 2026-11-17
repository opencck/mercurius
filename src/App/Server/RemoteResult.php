<?php

declare(strict_types=1);

namespace OpenCCK\App\Server;

final class RemoteResult {
    public const TIMED_OUT = 124;

    public function __construct(
        /** null when the server closed the channel without a status */
        public readonly ?int $exitCode,
        public readonly string $stdout,
        public readonly string $stderr,
        /** some of the output did not fit */
        public readonly bool $truncated,
        public readonly int $durationMs,
    ) {}
}
