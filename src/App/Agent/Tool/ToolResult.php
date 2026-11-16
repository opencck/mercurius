<?php

declare(strict_types=1);

namespace OpenCCK\App\Agent\Tool;

final class ToolResult {
    public function __construct(
        public readonly string $content,
        public readonly bool $isError = false,
    ) {}

    public static function ok(string $content): self {
        return new self($content);
    }

    public static function error(string $content): self {
        return new self($content, true);
    }
}
