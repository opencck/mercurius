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
        // spec: brief/internet.md#модуль-whisper-без-настройки — the whisper module of this installation is an endpoint
        //      by itself: its token in .env is all the owner sets
        $local = null;
        if (Env::raw($env, 'WHISPER_TOKEN') !== null) {
            $endpoints[self::WHISPER] ??= rtrim(Env::raw($env, 'WHISPER_URL') ?? 'http://whisper:8000', '/') . '/v1';
            $local = 'custom:' . self::WHISPER . '/' . (Env::raw($env, 'WHISPER_MODEL') ?? 'small');
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
                if (!self::usable($kind, $ref, $endpoints)) {
                    throw ConfigException::invalid($key, $kind === 'image'
                        ? 'image models of OpenRouter: openrouter:author/slug or author/slug'
                        : 'models: provider:model (openrouter, openai, codex, anthropic, bedrock, chatgpt, claude), author/slug or custom:<endpoint from MEDIA_ENDPOINTS>/model');
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
        $settings = new self(
            $models,
            $rules,
            $endpoints,
            array_values(array_unique($auto)),
            Env::positiveInt($env, 'MEDIA_AUDIO_AUTO_MINUTES', 10, 240),
            $text,
            Env::positiveInt($env, 'MEDIA_INBOX_DAYS', 30, 3650),
        );
        $settings->localSpeech = $local;
        return $settings;
    }

    /** The endpoint and module name of speech recognition on this host */
    public const WHISPER = 'whisper';

    /** The model of the whisper module of this installation; null — the module is not set up */
    private ?string $localSpeech = null;

    /** @var (\Closure(): bool)|null whether the whisper module answers now */
    private ?\Closure $localUp = null;

    /**
     * A module that runs is used: while the whisper module answers and the owner named no model of speech, speech is
     * recognised by it — on this host, for no tokens. A list the owner fills comes first.
     *
     * spec: brief/internet.md#модуль-whisper-без-настройки
     *
     * @param \Closure(): bool $up
     */
    public function useLocal(\Closure $up): void {
        $this->localUp = $up;
    }

    /** The model of the local whisper module while it answers; null — there is none now. */
    public function localSpeech(): ?string {
        return $this->localSpeech !== null && $this->localUp !== null && ($this->localUp)() ? $this->localSpeech : null;
    }

    /**
     * Whether a reference can serve the kind: a model of a known provider or of a subscription, or of the owner's own
     * endpoint; pictures are drawn only by the image models of OpenRouter (POST /images).
     *
     * @param array<string, string> $endpoints
     */
    private static function usable(string $kind, string $ref, array $endpoints): bool {
        $custom = str_starts_with($ref, 'custom:') ? substr($ref, 7) : null;
        $provider = preg_match(self::REF, $ref, $parts) === 1 && !str_ends_with($ref, ':') ? (($parts[1] ?? '') === '' ? ModelRef::OPENROUTER : $parts[1]) : null;
        if ($kind === 'image' && ($custom !== null || $provider !== ModelRef::OPENROUTER)) {
            return false;
        }
        return $custom === null
            // a subscription is a provider too: a category may hold its models
            ? $provider !== null && (ProviderProfile::of($provider) !== null || in_array($provider, ModelRef::SUBSCRIPTIONS, true))
            : preg_match('~^([a-z0-9][a-z0-9_-]{0,31})/(\S+)$~', $custom, $match) === 1 && isset($endpoints[$match[1]]);
    }

    /** @var (\Closure(string): list<string>)|null the lists as they are now, by kind */
    private ?\Closure $now = null;

    /**
     * From here on the lists of models are read at each use: a model the owner names in the dialog works without a
     * restart. A name the kind cannot use is left out, so one slip does not take the whole category away.
     *
     * spec: brief/models.md#списки-моделей-по-категориям — the lists of media work at once
     *
     * @param \Closure(string): list<string> $now the kind => its list now
     */
    public function follow(\Closure $now): void {
        $this->now = $now;
    }

    /**
     * @param 'vision'|'video'|'speech'|'image'|string $kind
     * @return list<string>
     */
    public function models(string $kind): array {
        $named = $this->now === null || !in_array($kind, self::KINDS, true)
            ? $this->models[$kind] ?? []
            : array_values(array_filter(($this->now)($kind), fn(string $ref): bool => self::usable($kind, $ref, $this->endpoints)));
        if ($named === [] && $kind === 'speech') {
            $local = $this->localSpeech();
            return $local === null ? [] : [$local];
        }
        return $named;
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
