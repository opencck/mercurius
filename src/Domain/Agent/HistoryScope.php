<?php

declare(strict_types=1);

namespace OpenCCK\Domain\Agent;

/**
 * Which past conversations a reader may search: all of them (the owner in a private chat) or one chat's.
 *
 * spec: brief/decisions.md#контекст-по-месту-и-аудитории — the narrowest listener decides
 */
final class HistoryScope {
    private function __construct(
        public readonly ?string $channel,
        public readonly ?string $chatId,
        /** @var list<int> participants whose conversations are left out of all: former owners */
        public readonly array $hidden = [],
    ) {}

    /**
     * @param list<int> $hidden participants whose conversations stay out: former owners keep their private chats
     */
    public static function all(array $hidden = []): self {
        return new self(null, null, $hidden);
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
