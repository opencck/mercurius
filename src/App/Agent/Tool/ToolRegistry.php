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

    public function register(Tool $tool): void {
        $name = $tool->definition()->name;
        if (isset($this->tools[$name])) {
            throw new LogicException(sprintf('Tool "%s" is already registered', $name));
        }
        $this->tools[$name] = $tool;
    }

    public function get(string $name): ?Tool {
        return $this->tools[$name] ?? null;
    }

    /** @return list<string> tools the participant may use, in registration order */
    public function names(ParticipantPolicy $policy): array {
        return array_values(array_filter(array_keys($this->tools), $policy->allowsTool(...)));
    }

    /** @return list<ToolDefinition> */
    public function definitions(ParticipantPolicy $policy): array {
        return array_map(fn(string $name): ToolDefinition => $this->tools[$name]->definition(), $this->names($policy));
    }
}
