<?php

declare(strict_types=1);

namespace OpenCCK\Infrastructure\Media;

use Mercurius\Llm\MediaPart;

/** The pictures an image model drew (none — it did not), why not, and which model it was. */
final class MediaDrawing {
    /** @param list<MediaPart> $images */
    public function __construct(
        public readonly array $images,
        public readonly ?string $error,
        public readonly string $model,
    ) {}
}
