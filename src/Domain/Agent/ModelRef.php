<?php

declare(strict_types=1);

namespace OpenCCK\Domain\Agent;

use InvalidArgumentException;
use Stringable;

/**
 * A model of some provider: "openrouter:z-ai/glm-5.3-flash", "anthropic:claude-opus-5". Models are not only from
 * OpenRouter: direct APIs of other providers come later. An id without a provider prefix is an OpenRouter id, as
 * everything stored before providers were introduced.
 *
 * spec: brief/agent-loop.md#модели — a model is a pair of provider and id
 */
final class ModelRef implements Stringable {
    public const OPENROUTER = 'openrouter';
    public const OPENAI = 'openai';
    /** The OpenAI Responses API with the OpenAI key: the codex models and the models that always reason. */
    public const CODEX = 'codex';
    public const ANTHROPIC = 'anthropic';
    public const BEDROCK = 'bedrock';
    /** The Gemini API by a key of Google AI Studio. */
    public const GEMINI = 'gemini';
    /** Gemini in Google Cloud (Gemini Enterprise Agent Platform, formerly Vertex AI) by a service account. */
    public const VERTEX = 'vertex';
    /** Kimi models of the Kimi Platform by a key, paid by the token. */
    public const MOONSHOT = 'moonshot';
    /** the owner's Kimi Code subscription by a key of its console: paid by the plan, not by the token */
    public const KIMI = 'kimi';
    /** the owner's ChatGPT subscription through the Codex backend (sign-in by /codex login, not a key) */
    public const CHATGPT = 'chatgpt';
    /** the owner's Claude subscription through the module claude-code (sign-in by /claude login, not a key) */
    public const CLAUDE = 'claude';
    /** the owner's Google Antigravity plan through the module antigravity (sign-in by /antigravity login, not a key) */
    public const ANTIGRAVITY = 'antigravity';
    /** providers of a subscription: a sign-in of the owner (Kimi Code — a key of its console), no price per token */
    public const SUBSCRIPTIONS = [self::CHATGPT, self::CLAUDE, self::ANTIGRAVITY, self::KIMI];

    public function __construct(
        public readonly string $provider,
        public readonly string $model,
    ) {
        if (preg_match('/^[a-z0-9_-]+$/', $provider) !== 1 || $model === '') {
            throw new InvalidArgumentException('A model needs a provider of [a-z0-9_-] and a non-empty id');
        }
    }

    /** "provider:id", or an OpenRouter id ("author/slug", "author/slug:free") — its part before ":" has a slash. */
    public static function parse(string $ref): self {
        $colon = strpos($ref, ':');
        if ($colon !== false && !str_contains(substr($ref, 0, $colon), '/')) {
            return new self(substr($ref, 0, $colon), substr($ref, $colon + 1));
        }
        return new self(self::OPENROUTER, $ref);
    }

    public function __toString(): string {
        return $this->provider . ':' . $this->model;
    }
}
