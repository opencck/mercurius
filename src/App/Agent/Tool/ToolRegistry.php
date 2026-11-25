<?php

declare(strict_types=1);

namespace OpenCCK\App\Agent\Tool;

use LogicException;
use Mercurius\Llm\ToolDefinition;
use OpenCCK\Domain\Agent\ParticipantPolicy;

/**
 * spec: brief/agent-loop.md#инструменты — registry entry
 */
final class ToolRegistry {
    /** @var array<string, Tool> */
    private array $tools = [];
    /** @var array<string, array<string, Tool>> context => name => a tool of an MCP connection of that context */
    private array $scoped = [];
    /** @var (\Closure(string): bool)|null whether a module service is up */
    private ?\Closure $services = null;

    /**
     * Tools of module services are offered only while their service is up.
     *
     * spec: brief/skills-mcp.md#модули-сервисы-mcp-и-скиллы
     * @param \Closure(string): bool $up
     */
    public function withServices(\Closure $up): void {
        $this->services = $up;
    }

    /** @throws \InvalidArgumentException the name or the schema of the tool is not one a model can call */
    public function register(Tool $tool): void {
        $definition = $tool->definition();
        $name = $definition->name;
        if (isset($this->tools[$name])) {
            throw new LogicException(sprintf('Tool "%s" is already registered', $name));
        }
        $problem = self::problem($name, $definition->parameters);
        if ($problem !== null) {
            throw new \InvalidArgumentException(sprintf('Tool "%s" is not registered: %s', mb_substr($name, 0, 80), $problem));
        }
        $this->tools[$name] = $tool;
    }

    /**
     * What is wrong with a tool for a model; null — nothing. A missing type of the root is taken as an object.
     *
     * spec: brief/agent-loop.md#инструменты — a tool is checked when it is registered (an MCP one above all)
     * ref: references/hermes-agent/tools/mcp_tool_schema.py@e96221fa:L78-L123 (_repair_object_shape) — hermes fills a
     *      missing object type; what cannot be read as an object of named properties is refused here
     * @param array<array-key, mixed> $parameters
     */
    private static function problem(string $name, array $parameters): ?string {
        if (preg_match('~^[A-Za-z0-9_-]{1,64}$~', $name) !== 1) {
            return 'the name is not 1-64 letters, digits, _ or -';
        }
        if (($parameters['type'] ?? 'object') !== 'object') {
            return 'the parameters are not an object';
        }
        $properties = $parameters['properties'] ?? [];
        if (!is_array($properties)) {
            return 'the properties are not a map';
        }
        foreach ($properties as $property => $schema) {
            if (!is_string($property) || !is_array($schema)) {
                return 'a property is not a named schema';
            }
        }
        $required = $parameters['required'] ?? [];
        if (!is_array($required)) {
            return 'required is not a list';
        }
        foreach ($required as $field) {
            if (!is_string($field) || !array_key_exists($field, $properties)) {
                return 'required names a property that is not there';
            }
        }
        return null;
    }

    /**
     * A tool of an MCP connection: offered only to runs of its context. False — the name is taken by a common tool or
     * by another connection of the context (the connection goes into the state names).
     *
     * spec: brief/skills-mcp.md#процессы-и-реестр-инструментов-по-контекстам — names are checked within the context
     * @throws \InvalidArgumentException the name or the schema of the tool is not one a model can call
     */
    public function registerIn(string $context, Tool $tool): bool {
        $definition = $tool->definition();
        $name = $definition->name;
        if (isset($this->tools[$name]) || isset($this->scoped[$context][$name])) {
            return false;
        }
        $problem = self::problem($name, $definition->parameters);
        if ($problem !== null) {
            throw new \InvalidArgumentException(sprintf('Tool "%s" is not registered: %s', mb_substr($name, 0, 80), $problem));
        }
        $this->scoped[$context][$name] = $tool;
        return true;
    }

    public function removeIn(string $context, string ...$names): void {
        foreach ($names as $name) {
            unset($this->scoped[$context][$name]);
        }
    }

    /** Takes tools out — those of an MCP server that was turned off or changed. */
    public function remove(string ...$names): void {
        foreach ($names as $name) {
            unset($this->tools[$name]);
        }
    }

    /** A common tool, else a tool of the run's context of MCP connections. */
    public function get(string $name, ?ParticipantPolicy $policy = null): ?Tool {
        $context = $policy?->mcpContext;
        return $this->tools[$name] ?? ($context === null ? null : ($this->scoped[$context][$name] ?? null));
    }

    /** @return list<string> tools the participant may use, in registration order: the common ones, then the context's */
    public function names(ParticipantPolicy $policy): array {
        $context = $policy->mcpContext;
        // a common tool registered after a connection took its name (the mail module came back) is offered once
        $scoped = $context === null ? [] : array_keys(array_diff_key($this->scoped[$context] ?? [], $this->tools));
        $names = [...array_keys($this->tools), ...$scoped];
        return array_values(array_filter($names, fn(string $name): bool => $this->allows($policy, $name)));
    }

    /**
     * Whether the participant may call the tool: by the policy, and a tool that asks the owner for access to anyone
     * but the owner (the owner has every tool already).
     *
     * spec: brief/network.md#участник-просит-инструмент
     */
    public function allows(ParticipantPolicy $policy, string $name): bool {
        $tool = $this->get($name, $policy);
        if ($tool instanceof NeedsService && ($this->services === null || !($this->services)($tool->service()))) {
            return false;
        }
        if ($tool instanceof Available && !$tool->available()) {
            // spec: brief/models.md#категории-моделей — a tool of an empty category is not offered
            return false;
        }
        if ($tool instanceof RequestsAccess) {
            // a worker cannot ask the owner (spec: brief/orchestration.md#часть-1--синхронное-делегирование)
            return $policy->tools !== null && $policy->irreversible;
        }
        if ($tool instanceof ToolSearchTool) {
            // spec: brief/skills-mcp.md#mcp-ресурсы-и-поиск-инструментов — the bridge shows only the tools the participant may use, so anyone may search
            return true;
        }
        if ($tool !== null && !isset($this->tools[$name]) && $policy->mcpOpen) {
            // spec: brief/skills-mcp.md#процессы-и-реестр-инструментов-по-контекстам — a connection of the run's context
            //       was approved by the owner of that context: whoever works in the place uses it, without a place on
            //       their list; a narrowed run (a worker, the self-review, a stranger in the group) goes by its list
            return true;
        }
        return $tool !== null && $policy->allowsTool($name);
    }

    /** @return list<string> every registered tool */
    public function all(): array {
        return array_keys($this->tools);
    }

    /** @return list<ToolDefinition> */
    public function definitions(ParticipantPolicy $policy): array {
        return array_values(array_filter(array_map(fn(string $name): ?ToolDefinition => $this->get($name, $policy)?->definition(), $this->names($policy))));
    }
}
