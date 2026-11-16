<?php

declare(strict_types=1);

namespace OpenCCK\Domain\Agent;

/**
 * A tool call as it is saved before execution.
 */
final class StoredToolCall {
    /**
     * @param array<string, mixed>|null $args null when the JSON did not parse
     * @param string $argumentsJson arguments exactly as they go back to the model
     */
    public function __construct(
        public readonly string $callId,
        public readonly int $position,
        public readonly string $name,
        public readonly ?array $args,
        public readonly string $argumentsJson,
        public readonly DangerClass $danger,
        public readonly ToolCallStatus $status,
    ) {}
}
