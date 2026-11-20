<?php

declare(strict_types=1);

namespace OpenCCK\Domain\Agent;

/**
 * An obligation to deliver a final answer to a chat.
 *
 * spec: brief/agent-loop.md#подходы — an answer is not lost to a failed send or a restart
 */
final class Delivery {
    public const PENDING = 'pending';
    public const ATTEMPTING = 'attempting';
    public const DELIVERED = 'delivered';
    public const FAILED = 'failed';
    public const ABANDONED = 'abandoned';

    public function __construct(
        public readonly int $id,
        public readonly ?int $runId,
        public readonly string $channel,
        public readonly string $chatId,
        public readonly ?string $threadId,
        public readonly ?string $replyTo,
        public readonly string $text,
        public readonly string $parseMode,
        /** parts already delivered */
        public readonly int $sent,
        public readonly string $status,
        public readonly int $attempts,
        /** unix time it was recorded */
        public readonly int $createdAt,
    ) {}
}
