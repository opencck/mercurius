<?php

declare(strict_types=1);

namespace OpenCCK\Domain\Entity;

/**
 * One stored message of a conversation, as the model will see it in the history.
 */
final class ChatTurn {
    /** @param 'user'|'assistant' $role */
    public function __construct(
        public readonly string $role,
        public readonly string $content,
    ) {}
}
