<?php

declare(strict_types=1);

namespace OpenCCK\App\Agent\Tool;

use LogicException;
use Mercurius\Llm\ToolDefinition;
use OpenCCK\Domain\Agent\ParticipantPolicy;

/**
 * spec: BRIEF.md#инструменты — registry entry
 */
final class ToolRegistry {
    /** @var array<string, Tool> */
    private array $tools = [];
    /** @var (\Closure(string): bool)|null whether a module service is up */
    private ?\Closure $services = null;

    /**
     * Tools of module services are offered only while their service is up.
     *
     * spec: BRIEF.md#модули-сервисы-mcp-и-скиллы
     * @param \Closure(string): bool $up
     */
    public function withServices(\Closure $up): void {
        $this->services = $up;
    }

    public function register(Tool $tool): void {
        $name = $tool->definition()->name;
        if (isset($this->tools[$name])) {
            throw new LogicException(sprintf('Tool "%s" is already registered', $name));
        }
        $this->tools[$name] = $tool;
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
     * spec: BRIEF.md#участник-просит-инструмент-решение-владельца-2026-11-17
     */
    public function allows(ParticipantPolicy $policy, string $name): bool {
        $tool = $this->tools[$name] ?? null;
        if ($tool instanceof NeedsService && ($this->services === null || !($this->services)($tool->service()))) {
            return false;
        }
        if ($tool instanceof RequestsAccess) {
            return $policy->tools !== null;
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
