<?php

declare(strict_types=1);

namespace OpenCCK\App\Agent\Model;

use InvalidArgumentException;
use OpenCCK\Domain\Agent\ModelRef;
use OpenCCK\Domain\Agent\ModelRole;
use OpenCCK\Infrastructure\Config\Env;

/**
 * The models the owner allowed, by category. The agent chooses a model for a task itself, but only among these: a
 * model that is in no list is never called. A new installation has one model — the starting one from the settings —
 * in each of the three text lists and nothing in the others; the owner fills them as the need comes.
 *
 * spec: brief/models.md#списки-моделей-по-категориям
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
    ];

    /**
     * @param array<string, list<ModelRef>> $text the lists of the text categories, by role
     * @param array<string, list<string>> $other the lists of the other categories, as the owner wrote them
     */
    public function __construct(
        private readonly array $text,
        private readonly array $other = [],
    ) {}

    /**
     * @param array<array-key, mixed> $env
     * @param non-empty-string $start the starting model: the only one of a text list the owner has not filled
     */
    public static function fromEnv(array $env, string $start): self {
        $text = [];
        foreach (self::TEXT as $role => $setting) {
            $list = self::refs(Env::raw($env, $setting) ?? '');
            $text[$role] = $list === [] ? self::refs($start) : $list;
        }
        $other = [];
        foreach (self::OTHER as $category => $setting) {
            $other[$category] = self::names(Env::raw($env, $setting) ?? '');
        }
        return new self($text, $other);
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
        return $this->text[$role] ?? [];
    }

    /** @return list<string> the models of another category, as written */
    public function other(string $category): array {
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

    /** Whether the model is in any of the text lists: only such a model answers in a conversation. */
    public function allowsText(ModelRef $model): bool {
        foreach (array_keys(self::TEXT) as $role) {
            if ($this->allows($role, $model)) {
                return true;
            }
        }
        return false;
    }
}
