<?php

declare(strict_types=1);

namespace OpenCCK\Infrastructure\Media;

/** What a media model said (null — it did not), why not, and which model it was. */
final class MediaAnswer {
    public function __construct(
        public readonly ?string $text,
        public readonly ?string $error,
        public readonly string $model,
    ) {}
}
