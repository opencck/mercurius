<?php

declare(strict_types=1);

namespace OpenCCK\Domain\Media;

/**
 * How a participant wants one kind of file handled: at once, on request or not at all; the model they prefer among
 * the owner's; their principles of choosing a model, in words. A null field — the owner's default.
 *
 * spec: brief/internet.md#как-влияют-пользователи — participants change it for themselves; the owner's settings are only the default
 */
final class MediaPref {
    public const KINDS = ['voice', 'audio', 'image', 'video', 'text', 'office'];
    public const MODES = ['auto', 'demand', 'off'];

    public function __construct(
        public readonly string $kind,
        public readonly ?string $mode = null,
        public readonly ?string $model = null,
        public readonly string $rules = '',
    ) {}
}
