<?php

declare(strict_types=1);

namespace OpenCCK\App\Agent\Model;

use Closure;
use InvalidArgumentException;
use OpenCCK\Domain\Agent\ModelRef;
use OpenCCK\Domain\Agent\ModelRole;
use OpenCCK\Infrastructure\Config\Env;

/**
 * The models the owner allowed, by category. The agent chooses a model for a task itself, but only among these: a
 * model that is in no list is never called. A new installation has every category empty but the fallback one: a text
 * category the owner left empty takes the fallback models, and a run whose model failed moves to them. Without fallback
 * models an empty text category is the starting model of the settings, as before. Read live, the lists follow the
 * settings without a restart.
 *
 * spec: brief/models.md#списки-моделей-по-категориям
 * spec: brief/models.md#запасные-модели
 */
final class ModelLists {
    /** the text categories — the roles of a conversation's model — and the setting each list lies in */
    public const TEXT = [
        ModelRole::CHEAP => 'LLM_CHEAP_MODELS',
        ModelRole::MAIN => 'LLM_MAIN_MODELS',
        ModelRole::STRONG => 'LLM_STRONG_MODELS',
    ];
    /** every other category: its setting; empty until the owner names a model */
    public const OTHER = [
        'search' => 'WEB_SEARCH_MODELS',
        'vision' => 'MEDIA_VISION_MODELS',
        'image' => 'MEDIA_IMAGE_MODELS',
        'speech' => 'MEDIA_SPEECH_MODELS',
        'voice' => 'MEDIA_AUDIO_GEN_MODELS',
        'video' => 'MEDIA_VIDEO_MODELS',
        'film' => 'MEDIA_VIDEO_GEN_MODELS',
        'sleep' => 'MEMORY_SLEEP_MODELS',
    ];

    /** the setting of the fallback text models */
    public const FALLBACK = 'LLM_FALLBACK_MODELS';
    /** the name of the fallback category in the tools and commands */
    public const FALLBACK_CATEGORY = 'fallback';

    /** seconds a live read of the lists is kept: the lists are asked at every choice of a model */
    public const REREAD = 5.0;

    /** @var (Closure(string): ?string)|null the setting by its name, now; null — the lists are fixed */
    private ?Closure $read = null;
    /** @var (Closure(): float)|null */
    private ?Closure $clock = null;
    /** @var non-empty-string|null */
    private ?string $start = null;
    private float $readAt = -INF;

    /**
     * @param array<string, list<ModelRef>> $text the lists of the text categories, by role
     * @param array<string, list<string>> $other the lists of the other categories, as the owner wrote them
     * @param list<ModelRef> $fallback the fallback text models
     */
    public function __construct(
        private array $text,
        private array $other = [],
        private array $fallback = [],
    ) {}

    /**
     * Lists read from the settings as they are now, again after REREAD seconds or after forget().
     *
     * spec: brief/models.md#списки-моделей-по-категориям — a list changed in the dialog works at once
     *
     * @param Closure(string): ?string $read
     * @param non-empty-string $start
     * @param (Closure(): float)|null $clock
     */
    public static function live(Closure $read, string $start, ?Closure $clock = null): self {
        $lists = new self([], []);
        $lists->read = $read;
        $lists->start = $start;
        $lists->clock = $clock;
        return $lists;
    }

    /** A list was just changed: the next question reads the settings again. */
    public function forget(): void {
        $this->readAt = -INF;
    }

    private function fresh(): void {
        $read = $this->read;
        $start = $this->start;
        if ($read === null || $start === null) {
            return;
        }
        $clock = $this->clock;
        $now = $clock === null ? microtime(true) : $clock();
        if ($now - $this->readAt < self::REREAD) {
            return;
        }
        $this->readAt = $now;
        $env = [];
        foreach ([...array_values(self::TEXT), ...array_values(self::OTHER), self::FALLBACK] as $setting) {
            $value = $read($setting);
            if ($value !== null) {
                $env[$setting] = $value;
            }
        }
        $all = self::fromEnv($env, $start);
        [$this->text, $this->other, $this->fallback] = [$all->text, $all->other, $all->fallback];
    }

    /**
     * @param array<array-key, mixed> $env
     * @param non-empty-string $start the starting model: the only one of a text list the owner has not filled
     */
    public static function fromEnv(array $env, string $start): self {
        $fallback = self::refs(Env::raw($env, self::FALLBACK) ?? '');
        $text = [];
        foreach (self::TEXT as $role => $setting) {
            $list = self::refs(Env::raw($env, $setting) ?? '');
            $text[$role] = $list !== [] ? $list : ($fallback !== [] ? $fallback : self::refs($start));
        }
        $other = [];
        foreach (self::OTHER as $category => $setting) {
            $names = self::names(Env::raw($env, $setting) ?? '');
            $other[$category] = $category === 'sleep' ? array_values(array_filter($names, static fn(string $name): bool => !self::interactiveOnly($name))) : $names;
        }
        return new self($text, $other, $fallback);
    }

    /**
     * Whether a model may answer only a living conversation: the Kimi Code subscription forbids non-interactive
     * automation, so the sleep of memory never calls it.
     *
     * spec: brief/models.md#подписка-kimi-code-условия-и-лимиты
     */
    public static function interactiveOnly(string $model): bool {
        return str_starts_with(trim($model), ModelRef::KIMI . ':');
    }

    /** @return list<string> */
    private static function names(string $list): array {
        return array_values(array_unique(array_filter(array_map('trim', explode(',', $list)), static fn(string $name): bool => $name !== '')));
    }

    /** @return list<ModelRef> what cannot be read as a model is left out */
    private static function refs(string $list): array {
        $found = [];
        foreach (self::names($list) as $name) {
            try {
                $ref = ModelRef::parse($name);
            } catch (InvalidArgumentException) {
                continue;
            }
            $found[(string) $ref] = $ref;
        }
        return array_values($found);
    }

    /** @return list<ModelRef> the models of a text category, in the owner's order */
    public function of(string $role): array {
        $this->fresh();
        return $this->text[$role] ?? [];
    }

    /** @return list<ModelRef> the fallback text models the owner named; empty — none */
    public function fallback(): array {
        $this->fresh();
        return $this->fallback;
    }

    /** @return list<string> the models of another category, as written */
    public function other(string $category): array {
        $this->fresh();
        return $this->other[$category] ?? [];
    }

    public function allows(string $role, ModelRef $model): bool {
        foreach ($this->of($role) as $allowed) {
            if ((string) $allowed === (string) $model) {
                return true;
            }
        }
        return false;
    }

    /** Whether the model is in any of the text lists or the fallback one: only such a model answers in a conversation. */
    public function allowsText(ModelRef $model): bool {
        foreach (array_keys(self::TEXT) as $role) {
            if ($this->allows($role, $model)) {
                return true;
            }
        }
        foreach ($this->fallback() as $allowed) {
            if ((string) $allowed === (string) $model) {
                return true;
            }
        }
        return false;
    }
}
