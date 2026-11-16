<?php

declare(strict_types=1);

namespace OpenCCK\Test\Support;

use Mercurius\Llm\LlmException;

/** A scripted stream that sends some text, then breaks. */
final class BrokenStream {
    public function __construct(
        public readonly string $text,
        public readonly LlmException $error,
    ) {}
}
