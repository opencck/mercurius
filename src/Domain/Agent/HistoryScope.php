<?php

declare(strict_types=1);

namespace OpenCCK\Domain\Agent;

/**
 * Which past conversations a reader may search: all of them (the owner in a private chat) or one chat's.
 *
 * spec: BRIEF.md#контекст-по-месту-и-аудитории — the narrowest listener decides
 */
final class HistoryScope {
    private function __construct(
        public readonly ?string $channel,
        public readonly ?string $chatId,
    ) {}

    public static function all(): self {
        return new self(null, null);
    }

    public static function chat(string $channel, string $chatId): self {
        return new self($channel, $chatId);
    }

    public function isAll(): bool {
        return $this->channel === null;
    }

    public function allows(string $channel, string $chatId): bool {
        return $this->channel === null || ($this->channel === $channel && $this->chatId === $chatId);
    }
}
