<?php

declare(strict_types=1);

namespace OpenCCK\App\Service;

use function Mercurius\I18n\t;

/**
 * Core layer of the agent: identity and basic rules. It never changes during a session (prompt cache),
 * so it must not contain live values such as the current time.
 *
 * spec: brief/memory.md#профиль-и-сводка — layer 0, changed only by the owner
 * ref: references/hermes-agent/agent/prompt_builder.py@e96221fa:L344-L362 (TOOL_USE_ENFORCEMENT_GUIDANCE) — act through a
 *      tool in the same answer, never report a step that no tool did; hermes gates it by model family (glm among
 *      them), here it is for every model: the prompt is fixed per conversation, and the rule costs a few lines
 */
final class SystemPrompt {
    public const VERSION = 8;

    public static function text(): string {
        // spec: brief/operations.md#язык-агента — the prompt in the agent's language
        return t('prompt.system');
    }
}
