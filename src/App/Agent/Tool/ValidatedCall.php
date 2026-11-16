<?php

declare(strict_types=1);

namespace OpenCCK\App\Agent\Tool;

use Mercurius\Llm\ToolCall;
use OpenCCK\Domain\Agent\DangerClass;

/**
 * A tool call after validation. With an error it is never executed: the error goes back to the model as its result.
 */
final class ValidatedCall {
    /**
     * @param array<string, mixed>|null $args null when the arguments did not parse
     */
    public function __construct(
        public readonly ToolCall $call,
        public readonly ?Tool $tool,
        public readonly ?array $args,
        public readonly ?string $error,
        public readonly DangerClass $danger,
    ) {}

    public function executable(): bool {
        return $this->error === null && $this->tool !== null && $this->args !== null;
    }
}
