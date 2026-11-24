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

    /** Takes tools out — those of an MCP server that was turned off or changed. */
    public function remove(string ...$names): void {
        foreach ($names as $name) {
            unset($this->tools[$name]);
        }
    }

    public function get(string $name): ?Tool {
        return $this->tools[$name] ?? null;
    }

    /** @return list<string> tools the participant may use, in registration order */
    public function names(ParticipantPolicy $policy): array {
        return array_values(array_filter(array_keys($this->tools), fn(string $name): bool => $this->allows($policy, $name)));
    }

    /**
     * Whether the participant may call the tool: by the policy, and a tool that asks the owner for access to anyone
     * but the owner (the owner has every tool already).
     *
     * spec: brief/network.md#участник-просит-инструмент
     */
    public function allows(ParticipantPolicy $policy, string $name): bool {
        $tool = $this->tools[$name] ?? null;
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
        return $tool !== null && $policy->allowsTool($name);
    }

    /** @return list<string> every registered tool */
    public function all(): array {
        return array_keys($this->tools);
    }

    /** @return list<ToolDefinition> */
    public function definitions(ParticipantPolicy $policy): array {
        return array_map(fn(string $name): ToolDefinition => $this->tools[$name]->definition(), $this->names($policy));
    }
}
