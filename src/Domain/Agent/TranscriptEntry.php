<?php

declare(strict_types=1);

namespace OpenCCK\Domain\Agent;

use Mercurius\Llm\Message;

/**
 * A stored message with its id, as compaction needs it: where the boundary is, which result belongs to which tool.
 */
final class TranscriptEntry {
    public function __construct(
        public readonly int $id,
        public readonly Message $message,
        /** name of the tool whose result this is; null for other messages */
        public readonly ?string $toolName = null,
    ) {}
}
