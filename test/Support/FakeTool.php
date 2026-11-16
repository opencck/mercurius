<?php

declare(strict_types=1);

namespace OpenCCK\Test\Support;

use Closure;
use Mercurius\Llm\ToolDefinition;
use OpenCCK\App\Agent\Tool\Tool;
use OpenCCK\App\Agent\Tool\ToolContext;
use OpenCCK\App\Agent\Tool\ToolResult;
use OpenCCK\Domain\Agent\DangerClass;

use function Amp\delay;

/**
 * A configurable tool: returns "<name>:<json args>" or what $handler returns, after an optional delay.
 */
final class FakeTool implements Tool {
    /** @var list<array<string, mixed>> */
    public array $calls = [];

    /**
     * @param array<string, mixed> $parameters
     * @param (Closure(array<string, mixed>, ToolContext): ToolResult)|null $handler
     * @param positive-int $timeout
     */
    public function __construct(
        private readonly string $name = 'fake',
        private readonly DangerClass $danger = DangerClass::Read,
        private readonly array $parameters = ['type' => 'object', 'properties' => ['q' => ['type' => 'string']]],
        private readonly ?Closure $handler = null,
        private readonly float $delay = 0.0,
        private readonly bool $untrusted = false,
        private readonly int $timeout = 30,
    ) {}

    public function definition(): ToolDefinition {
        return new ToolDefinition($this->name, 'Fake tool ' . $this->name, $this->parameters);
    }

    public function danger(array $args): DangerClass {
        return $this->danger;
    }

    public function untrusted(): bool {
        return $this->untrusted;
    }

    public function timeout(): int {
        return $this->timeout;
    }

    public function execute(array $args, ToolContext $context): ToolResult {
        $this->calls[] = $args;
        if ($this->delay > 0) {
            delay($this->delay, cancellation: $context->cancellation);
        }
        if ($this->handler !== null) {
            return ($this->handler)($args, $context);
        }
        return ToolResult::ok($this->name . ':' . json_encode($args, JSON_THROW_ON_ERROR));
    }
}
