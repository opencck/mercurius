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
 * spec: BRIEF.md#модели — a model is a pair of provider and id
 */
final class ModelRef implements Stringable {
    public const OPENROUTER = 'openrouter';
    public const OPENAI = 'openai';
    public const ANTHROPIC = 'anthropic';

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
