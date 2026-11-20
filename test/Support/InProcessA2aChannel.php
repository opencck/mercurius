<?php

declare(strict_types=1);

namespace OpenCCK\Test\Support;

use Amp\Cancellation;
use Amp\DeferredFuture;
use Mercurius\Peer\A2aChannel;

/**
 * Two ends of one connection in memory: what one sends the other receives, in order; closing one closes both.
 */
final class InProcessA2aChannel implements A2aChannel {
    /** @var list<string> */
    private array $inbox = [];
    /** @var DeferredFuture<null>|null */
    private ?DeferredFuture $waiting = null;
    private bool $closed = false;
    private ?self $other = null;
    /** @var list<string> every frame sent from this end */
    public array $sent = [];

    /** @return array{self, self} */
    public static function pair(): array {
        $a = new self();
        $b = new self();
        $a->other = $b;
        $b->other = $a;
        return [$a, $b];
    }

    public function send(string $text): void {
        if ($this->closed) {
            throw new \RuntimeException('closed');
        }
        $this->sent[] = $text;
        $this->other?->deliver($text);
    }

    public function receive(?Cancellation $cancellation = null): ?string {
        while ($this->inbox === [] && !$this->closed) {
            $this->waiting ??= new DeferredFuture();
            $this->waiting->getFuture()->await($cancellation);
        }
        return array_shift($this->inbox);
    }

    public function close(): void {
        if ($this->closed) {
            return;
        }
        $this->closed = true;
        $this->wake();
        $this->other?->close();
    }

    public function isClosed(): bool {
        return $this->closed;
    }

    private function deliver(string $text): void {
        $this->inbox[] = $text;
        $this->wake();
    }

    private function wake(): void {
        $waiting = $this->waiting;
        $this->waiting = null;
        $waiting?->complete(null);
    }
}
