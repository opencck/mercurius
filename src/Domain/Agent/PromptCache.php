<?php

declare(strict_types=1);

namespace OpenCCK\Domain\Agent;

/**
 * How a provider caches the prefix of a conversation.
 *
 * spec: BRIEF.md#провайдеры-моделей-openrouter-и-openai
 */
enum PromptCache: string {
    /** Claude: only when asked — a mark on the system prompt and a top-level `cache_control` that follows the tail. */
    case Marks = 'marks';
    /** OpenAI: on its own, routed by a `prompt_cache_key` that names the conversation. */
    case Key = 'key';
    /** The provider caches on its own, nothing to send. */
    case Provider = 'provider';
}
