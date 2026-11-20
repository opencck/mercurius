<?php

declare(strict_types=1);

namespace OpenCCK\Infrastructure\Config;

use OpenCCK\Domain\Agent\ModelRef;
use OpenCCK\Domain\Agent\ProviderProfile;

/**
 * Models and rules for what comes with messages: vision, video and speech models per kind (unset by default — the
 * owner's decision: until one is named, the agent does not look), the image models that draw, endpoints of the owner's
 * own models (OpenAI-compatible), which kinds are processed at once, and the thresholds.
 *
 * spec: brief/internet.md#модели-вложений — models are not set by default; a reference like a text model's, plus custom:
 * spec: brief/models.md#категории-моделей — a category is a list of models, or none
 */
final class MediaSettings {
    public const KINDS = ['vision', 'video', 'speech', 'image'];
    /** What is processed at once by default; the rest on request. */
    public const AUTO = ['voice', 'audio', 'image', 'text', 'office'];
    public const AUTO_KINDS = ['voice', 'audio', 'image', 'video', 'text', 'office'];
    /** provider:model of a provider profile, or a bare author/slug (OpenRouter); a colon before the slash only in a prefix */
    private const REF = '~^(?:([a-z][a-z0-9_-]*):)?[A-Za-z0-9][A-Za-z0-9._-]*(?:/[A-Za-z0-9._:-]+)?$~';
    private const NAME = '~^[a-z0-9][a-z0-9_-]{0,31}$~';

    /**
     * @param array<string, list<string>> $models kind => references, cheapest first is chosen among them
     * @param array<string, string> $endpoints name => base URL of an OpenAI-compatible API
     * @param list<string> $auto kinds processed at once
     * @param array<string, string> $rules kind => the owner's principles of choosing among its models
     */
    private function __construct(
        private readonly array $models,
        private readonly array $rules,
        public readonly array $endpoints,
        public readonly array $auto,
        public readonly int $audioAutoMinutes,
        public readonly int $textLimit,
        public readonly int $inboxDays,
    ) {}

    /**
     * @param array<array-key, mixed> $env
     * @throws ConfigException
     */
    public static function fromEnv(array $env): self {
        $endpoints = [];
        foreach (self::list(Env::raw($env, 'MEDIA_ENDPOINTS')) as $pair) {
            [$name, $url] = array_map('trim', explode('=', $pair, 2)) + [1 => ''];
            if (preg_match(self::NAME, $name) !== 1 || preg_match('~^https?://[^\s/]+(/\S*)?$~', $url) !== 1) {
                throw ConfigException::invalid('MEDIA_ENDPOINTS', 'name=http(s)://address, separated by commas');
            }
            $endpoints[$name] = rtrim($url, '/');
        }
        $models = [];
        $rules = [];
        foreach (self::KINDS as $kind) {
            // spec: brief/internet.md#как-влияют-пользователи — the owner's principles of choosing a model for this kind, in words
            $rule = trim(Env::raw($env, 'MEDIA_' . strtoupper($kind) . '_RULES') ?? '');
            if (mb_strlen($rule) > 1000) {
                throw ConfigException::invalid('MEDIA_' . strtoupper($kind) . '_RULES', 'up to 1000 characters');
            }
            $rules[$kind] = $rule;
            $key = 'MEDIA_' . strtoupper($kind) . '_MODELS';
            $models[$kind] = [];
            foreach (self::list(Env::raw($env, $key)) as $ref) {
                $custom = str_starts_with($ref, 'custom:') ? substr($ref, 7) : null;
                $provider = preg_match(self::REF, $ref, $parts) === 1 && !str_ends_with($ref, ':') ? (($parts[1] ?? '') === '' ? ModelRef::OPENROUTER : $parts[1]) : null;
                $valid = $custom === null
                    ? $provider !== null && ProviderProfile::of($provider) !== null
                    : preg_match('~^([a-z0-9][a-z0-9_-]{0,31})/(\S+)$~', $custom, $match) === 1 && isset($endpoints[$match[1]]);
                // pictures are drawn by the image models of OpenRouter (POST /images)
                if ($kind === 'image' && ($custom !== null || $provider !== ModelRef::OPENROUTER)) {
                    $valid = false;
                }
                if (!$valid) {
                    throw ConfigException::invalid($key, $kind === 'image'
                        ? 'image models of OpenRouter: openrouter:author/slug or author/slug'
                        : 'models: provider:model (openrouter, openai, codex, anthropic, bedrock), author/slug or custom:<endpoint from MEDIA_ENDPOINTS>/model');
                }
                $models[$kind][] = $ref;
            }
        }
        $auto = Env::raw($env, 'MEDIA_AUTO');
        $auto = $auto === null ? self::AUTO : (strtolower(trim($auto)) === 'none' ? [] : self::list(strtolower($auto)));
        if (array_diff($auto, self::AUTO_KINDS) !== []) {
            throw ConfigException::invalid('MEDIA_AUTO', 'none or a list of ' . implode(', ', self::AUTO_KINDS));
        }
        $text = Env::positiveInt($env, 'MEDIA_TEXT_LIMIT', 20_000, 200_000);
        if ($text < 1000) {
            throw ConfigException::invalid('MEDIA_TEXT_LIMIT', 'at least 1000 characters');
        }
        return new self(
            $models,
            $rules,
            $endpoints,
            array_values(array_unique($auto)),
            Env::positiveInt($env, 'MEDIA_AUDIO_AUTO_MINUTES', 10, 240),
            $text,
            Env::positiveInt($env, 'MEDIA_INBOX_DAYS', 30, 3650),
        );
    }

    /**
     * @param 'vision'|'video'|'speech'|'image'|string $kind
     * @return list<string>
     */
    public function models(string $kind): array {
        return $this->models[$kind] ?? [];
    }

    /** The owner's principles for the kind, '' — none. */
    public function rules(string $kind): string {
        return $this->rules[$kind] ?? '';
    }

    /** @return list<string> */
    private static function list(?string $raw): array {
        return array_values(array_filter(array_map('trim', explode(',', $raw ?? '')), static fn(string $item): bool => $item !== ''));
    }
}
