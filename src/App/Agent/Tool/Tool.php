<?php

declare(strict_types=1);

namespace OpenCCK\App\Agent\Tool;

use Mercurius\Llm\ToolDefinition;
use OpenCCK\Domain\Agent\DangerClass;

/**
 * A registry entry: description and argument schema for the model, danger class, handler.
 * A handler returns errors as results; an exception is caught by the executor and becomes an error result.
 *
 * spec: brief/agent-loop.md#песочница
 */
interface Tool {
    public function definition(): ToolDefinition;

    /** @param array<string, mixed> $args already validated against the schema */
    public function danger(array $args): DangerClass;

    /** Results come from outside (web, MCP...) and are wrapped as data, not instructions. */
    public function untrusted(): bool;

    /** @return positive-int seconds; the run's tool timeout caps it */
    public function timeout(): int;

    /** @param array<string, mixed> $args */
    public function execute(array $args, ToolContext $context): ToolResult;
}
