<?php

declare(strict_types=1);

namespace OpenCCK\Infrastructure\Media;

use Amp\Cancellation;

/** Where a media model call is counted: the conversation and the run (null — before a run), and its cancellation. */
final class MediaCall {
    public function __construct(
        public readonly ?int $conversationId,
        public readonly ?int $runId,
        public readonly Cancellation $cancellation,
    ) {}
}
