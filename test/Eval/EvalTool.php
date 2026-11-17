<?php

declare(strict_types=1);

namespace OpenCCK\Test\Eval;

use Mercurius\Llm\ToolDefinition;
use OpenCCK\App\Agent\Tool\Tool;
use OpenCCK\App\Agent\Tool\ToolContext;
use OpenCCK\App\Agent\Tool\ToolResult;
use OpenCCK\Domain\Agent\DangerClass;

/**
 * A tool of an evaluation task: a real description and schema, a fixed result.
 */
final class EvalTool implements Tool {
    /** @param array<string, mixed> $parameters */
    public function __construct(
        private readonly string $name,
        private readonly string $description,
        private readonly DangerClass $danger,
        private readonly array $parameters,
        private readonly string $result,
        private readonly bool $error,
        private readonly bool $untrusted,
    ) {}

    public function definition(): ToolDefinition {
        return new ToolDefinition($this->name, $this->description, $this->parameters);
    }

    public function danger(array $args): DangerClass {
        return $this->danger;
    }

    public function untrusted(): bool {
        return $this->untrusted;
    }

    public function timeout(): int {
        return 10;
    }

    public function execute(array $args, ToolContext $context): ToolResult {
        return $this->error ? ToolResult::error($this->result) : ToolResult::ok($this->result);
    }
}
