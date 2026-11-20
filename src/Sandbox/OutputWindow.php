<?php

declare(strict_types=1);

namespace OpenCCK\Sandbox;

use function Mercurius\I18n\t;

/**
 * Keeps the beginning and the end of a stream while it is read, never more than head + tail bytes in memory.
 *
 * ref: references/hermes-agent/tools/environments/base.py@e96221fa:L726-L790 (execute, bounded_capture) — output is
 *      capped while the stream is read, not after it is collected
 */
final class OutputWindow {
    private string $head = '';
    private string $tail = '';
    private int $total = 0;

    /**
     * @param positive-int $headBytes
     * @param positive-int $tailBytes
     */
    public function __construct(
        private readonly int $headBytes = 25_000,
        private readonly int $tailBytes = 25_000,
    ) {}

    public function append(string $chunk): void {
        $this->total += strlen($chunk);
        $room = $this->headBytes - strlen($this->head);
        if ($room > 0) {
            $this->head .= substr($chunk, 0, $room);
            $chunk = substr($chunk, $room);
        }
        if ($chunk === '') {
            return;
        }
        $this->tail = substr($this->tail . $chunk, -$this->tailBytes);
    }

    public function text(): string {
        $skipped = $this->total - strlen($this->head) - strlen($this->tail);
        if ($skipped <= 0) {
            return $this->head . $this->tail;
        }
        return sprintf(t('sandbox.output_window.skipped'), $this->head, $skipped, $this->tail);
    }

    public function totalBytes(): int {
        return $this->total;
    }
}
