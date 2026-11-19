<?php

declare(strict_types=1);

namespace OpenCCK\App\Agent;

/**
 * Whether a model gets the rule to act through tools instead of describing what it would do. Some families answer in
 * prose with no tool call and end the turn on a promise; the rule goes with their requests that have tools.
 *
 * spec: BRIEF.md#агентский-цикл-и-харнесс — the rule to act through tools, by the model of the call
 * ref: references/hermes-agent/agent/prompt_builder.py@e96221fa:L344-L362 (TOOL_USE_ENFORCEMENT_GUIDANCE,
 *      TOOL_USE_ENFORCEMENT_MODELS) — the text and the families; nemotron is left out there by measurement, here too
 * ref: references/hermes-agent/agent/system_prompt.py@e96221fa:L42-L53 (_model_gate), L589-L594 — auto by the list,
 *      on/off, or the owner's list of parts of model names; here per request, as the model may change in a conversation
 */
final class ToolUseGuidance {
    public const FAMILIES = ['gpt', 'codex', 'gemini', 'gemma', 'grok', 'glm', 'qwen', 'deepseek', 'muse'];

    /** @var list<string>|bool true/false — on or off for every model; a list — parts of model names */
    private readonly array|bool $gate;

    /** @param string $setting AGENT_TOOL_USE_GUIDANCE: auto (default), on, off, or parts of model names, comma-separated */
    public function __construct(string $setting = 'auto') {
        $value = strtolower(trim($setting));
        $this->gate = match ($value) {
            '', 'auto' => self::FAMILIES,
            'on', 'true', '1', 'yes' => true,
            'off', 'false', '0', 'no' => false,
            default => array_values(array_filter(array_map('trim', explode(',', $value)), static fn(string $p): bool => $p !== '')),
        };
    }

    public function applies(string $model): bool {
        if (is_bool($this->gate)) {
            return $this->gate;
        }
        $model = strtolower($model);
        foreach ($this->gate as $part) {
            if (str_contains($model, $part)) {
                return true;
            }
        }
        return false;
    }
}
