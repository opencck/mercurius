<?php

declare(strict_types=1);

namespace OpenCCK\App\Settings;

use Amp\DeferredFuture;
use Amp\Future;
use Revolt\EventLoop;

/**
 * Asks the application to stop as on SIGTERM: runs pause and resume after the start; Docker starts the container again
 * (restart: unless-stopped), and new settings take effect.
 *
 * spec: brief/operations.md#трассировка-и-метрики
 */
final class Restarter {
    /** @var DeferredFuture<null> */
    private readonly DeferredFuture $requested;

    public function __construct() {
        $this->requested = new DeferredFuture();
    }

    /** In a second: the answer about the restart reaches the chat first. */
    public function request(float $delay = 1.0): void {
        EventLoop::delay($delay, function (): void {
            if (!$this->requested->isComplete()) {
                $this->requested->complete();
            }
        });
    }

    /** @return Future<null> */
    public function requested(): Future {
        return $this->requested->getFuture();
    }
}
