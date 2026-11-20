<?php

declare(strict_types=1);

namespace OpenCCK\App\Agent\Media;

use OpenCCK\Domain\Media\MediaPref;
use OpenCCK\Domain\Repository\MediaPrefRepository;
use OpenCCK\Infrastructure\Config\MediaSettings;

use function Mercurius\I18n\t;

/**
 * The way a participant's files are handled: their own choice over the owner's defaults (MEDIA_AUTO, the order of the
 * model lists, the owner's principles). The model a participant prefers is only one of the owner's for that kind.
 *
 * spec: brief/internet.md#входящие-вложения — how users influence it; the owner's settings are only the default
 */
final class MediaPrefs {
    /** what model a kind of file needs */
    public const MODEL_KIND = ['image' => 'vision', 'voice' => 'speech', 'audio' => 'speech', 'video' => 'video'];

    public function __construct(
        private readonly MediaPrefRepository $repository,
        public readonly MediaSettings $settings,
    ) {}

    /**
     * The models to try for a model kind, in order: the one asked for, the participant's preferred, then the owner's
     * list; only the owner's models, each once.
     *
     * @return list<string>
     */
    public static function candidates(MediaSettings $settings, string $modelKind, ?string $preferred, ?string $asked = null): array {
        $list = $settings->models($modelKind);
        $order = array_values(array_filter([$asked, $preferred], static fn(?string $m): bool => $m !== null && in_array($m, $list, true)));
        return array_values(array_unique([...$order, ...$list]));
    }

    /** The participant's way for the kind, with the owner's default filled in. */
    public function of(int $principalId, string $kind): MediaPref {
        $own = $this->repository->of($principalId)[$kind] ?? new MediaPref($kind);
        return new MediaPref($kind, $own->mode ?? (in_array($kind, $this->settings->auto, true) ? 'auto' : 'demand'), $own->model, $own->rules);
    }

    /**
     * Changes what is given; '' returns a field to the owner's default, null keeps it.
     *
     * @return string|null why it was not changed
     */
    public function set(int $principalId, string $kind, ?string $mode, ?string $model, ?string $rules): ?string {
        if (!in_array($kind, MediaPref::KINDS, true)) {
            return sprintf(t('media.prefs.bad_kind'), implode(', ', MediaPref::KINDS));
        }
        if ($mode !== null && $mode !== '' && !in_array($mode, MediaPref::MODES, true)) {
            return sprintf(t('media.prefs.bad_mode'), implode(', ', MediaPref::MODES));
        }
        $modelKind = self::MODEL_KIND[$kind] ?? null;
        if ($model !== null && $model !== '' && ($modelKind === null || !in_array($model, $this->settings->models($modelKind), true))) {
            return sprintf(t('media.prefs.bad_model'), $modelKind === null ? '—' : (implode(', ', $this->settings->models($modelKind)) ?: t('media.prefs.none')));
        }
        if ($rules !== null && mb_strlen($rules) > 1000) {
            return t('media.prefs.long_rules');
        }
        $own = $this->repository->of($principalId)[$kind] ?? new MediaPref($kind);
        $this->repository->save($principalId, new MediaPref(
            $kind,
            $mode === null ? $own->mode : ($mode === '' ? null : $mode),
            $model === null ? $own->model : ($model === '' ? null : $model),
            $rules === null ? $own->rules : trim($rules),
        ));
        return null;
    }

    /** The owner's models and principles and the participant's way, per kind — for the agent and for show. */
    public function describe(int $principalId): string {
        $lines = [];
        foreach (MediaPref::KINDS as $kind) {
            $pref = $this->of($principalId, $kind);
            $modelKind = self::MODEL_KIND[$kind] ?? null;
            $line = sprintf(t('media.prefs.line'), $kind, $pref->mode ?? 'demand');
            if ($modelKind !== null) {
                $models = $this->settings->models($modelKind);
                $line .= sprintf(t('media.prefs.models'), $models === [] ? t('media.prefs.none') : implode(', ', $models));
                $owner = $this->settings->rules($modelKind);
                if ($owner !== '') {
                    $line .= sprintf(t('media.prefs.owner_rules'), $owner);
                }
            }
            if ($pref->model !== null) {
                $line .= sprintf(t('media.prefs.preferred'), $pref->model);
            }
            if ($pref->rules !== '') {
                $line .= sprintf(t('media.prefs.own_rules'), $pref->rules);
            }
            $lines[] = $line;
        }
        return implode("\n", $lines);
    }
}
