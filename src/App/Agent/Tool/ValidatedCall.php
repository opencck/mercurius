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
        /** the call as the model made it, when it went through tool_call: the transcript keeps this one */
        public readonly ?ToolCall $wire = null,
    ) {}

    /** The same call made through the bridge `tool_call`. */
    public function through(ToolCall $wire): self {
        return new self($this->call, $this->tool, $this->args, $this->error, $this->danger, $wire);
    }

    public function executable(): bool {
        return $this->error === null && $this->tool !== null && $this->args !== null;
    }
}
