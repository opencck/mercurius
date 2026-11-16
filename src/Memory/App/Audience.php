<?php

declare(strict_types=1);

namespace OpenCCK\Memory\App;

/**
 * Which memory a conversation may see: what is known is disclosed by the weakest listener.
 *
 * spec: BRIEF.md#контекст-по-месту-и-аудитории — the owner's facts never reach a group or another participant
 */
final class Audience {
    public const OWNER = 'owner';

    /** @return list<string> scopes readable here; the first one is where new facts of this place go */
    public static function scopes(bool $owner, int $principalId, string $channel, string $chatId, bool $privateChat): array {
        $chat = self::chat($channel, $chatId);
        if (!$privateChat) {
            return [$chat];
        }
        return $owner ? [self::OWNER, $chat] : [self::principal($principalId), $chat];
    }

    public static function chat(string $channel, string $chatId): string {
        return sprintf('chat:%s:%s', $channel, $chatId);
    }

    public static function principal(int $principalId): string {
        return 'principal:' . $principalId;
    }
}
