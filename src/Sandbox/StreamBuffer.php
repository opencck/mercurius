<?php

declare(strict_types=1);

namespace OpenCCK\Sandbox;

use Amp\DeferredFuture;
use Revolt\EventLoop;

/**
 * Output of a long-lived process between its pipe and the client that polls it. The reader fiber appends what the pipe
 * gives; the client takes it with a wait (long polling). When nobody takes and the buffer is full, append() waits:
 * the pipe is not read, the process blocks on its own write — memory stays bounded and nothing is dropped.
 *
 * spec: brief/agent-loop.md#песочница — the streaming API of the executor
 * ref: .claude/skills/skill-amphp/docs/common-mistakes.md (18. receive buffer deadlock) — a stream must always be
 *      drained by someone; here the pause is deliberate and bounded by the client's polling
 */
final class StreamBuffer {
    private string $data = '';
    private bool $closed = false;
    /** @var DeferredFuture<null>|null resolves when data comes or the stream ends */
    private ?DeferredFuture $readable = null;
    /** @var DeferredFuture<null>|null resolves when the client took data */
    private ?DeferredFuture $writable = null;

    /** @param positive-int $limit bytes kept before the reader waits */
    public function __construct(private readonly int $limit) {}

    /** Called by the reader fiber; waits while the buffer is full. */
    public function append(string $chunk): void {
        while (strlen($this->data) >= $this->limit && !$this->closed) {
            $this->writable ??= new DeferredFuture();
            $this->writable->getFuture()->await();
        }
        if ($this->closed) {
            return;
        }
        $this->data .= $chunk;
        self::wake($this->readable);
    }

    /** The stream ended: what is buffered can still be taken; the waiting sides are woken. */
    public function close(): void {
        $this->closed = true;
        self::wake($this->readable);
        self::wake($this->writable);
    }

    /**
     * Up to $max bytes; waits up to $wait seconds when there is nothing yet. '' — nothing came in time or the stream
     * ended (see ended()).
     *
     * @param positive-int $max
     */
    public function take(int $max, float $wait): string {
        if ($this->data === '' && !$this->closed && $wait > 0) {
            $this->readable ??= new DeferredFuture();
            $future = $this->readable->getFuture();
            // a referenced timer: the wait itself keeps the loop alive (a TimeoutCancellation timer does not); when it
            // fires, every client waiting now gets an empty answer and asks again
            $timer = EventLoop::delay($wait, function () use ($future): void {
                if (!$future->isComplete()) {
                    self::wake($this->readable);
                }
            });
            try {
                $future->await();
            } finally {
                EventLoop::cancel($timer);
            }
        }
        $out = substr($this->data, 0, $max);
        $this->data = substr($this->data, strlen($out));
        if ($out !== '') {
            self::wake($this->writable);
        }
        return $out;
    }

    /** The stream ended and everything was taken. */
    public function ended(): bool {
        return $this->closed && $this->data === '';
    }

    public function pending(): int {
        return strlen($this->data);
    }

    /**
     * @param DeferredFuture<null>|null $deferred
     * @param-out null $deferred
     */
    private static function wake(?DeferredFuture &$deferred): void {
        $waiting = $deferred;
        $deferred = null;
        $waiting?->complete(null);
    }
}
