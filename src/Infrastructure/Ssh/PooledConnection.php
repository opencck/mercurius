<?php

declare(strict_types=1);

namespace OpenCCK\Infrastructure\Ssh;

use Closure;

/**
 * A connection the pool gives out: how many commands use it now and in all, when it was last used.
 *
 * spec: BRIEF.md#фаза-7-по-частям — a pool of SSH connections
 */
final class PooledConnection {
    public int $users = 0;
    public int $commands = 0;
    public int $lastUsed = 0;
    /** false — opened past the pool (busy or full) and closed after its command */
    public bool $pooled = true;
    private bool $closed = false;

    /**
     * @param Closure(): bool $alive
     * @param Closure(self): void $close gets the connection, to tell how many commands it carried
     */
    public function __construct(
        public readonly int $id,
        private readonly Closure $alive,
        private readonly Closure $close,
        /** what the caller needs to run commands: the SSH resources */
        public readonly mixed $resource = null,
    ) {}

    public function alive(): bool {
        return !$this->closed && ($this->alive)();
    }

    public function close(): void {
        if ($this->closed) {
            return;
        }
        $this->closed = true;
        ($this->close)($this);
    }
}
