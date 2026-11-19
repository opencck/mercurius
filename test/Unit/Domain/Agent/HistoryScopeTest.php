<?php

declare(strict_types=1);

namespace OpenCCK\Test\Unit\Domain\Agent;

use OpenCCK\Domain\Agent\HistoryScope;
use OpenCCK\Infrastructure\Repository\PostgresEpisodeRepository;
use PHPUnit\Framework\TestCase;

/**
 * The owner searches every past conversation but those of former owners: their private chats stay theirs.
 *
 * spec: BRIEF.md#несколько-пользователей
 */
final class HistoryScopeTest extends TestCase {
    public function testAllConversationsLeaveOutThoseOfFormerOwners(): void {
        self::assertSame(['', []], PostgresEpisodeRepository::scope(HistoryScope::all()));
        self::assertSame([' AND c.principal_id <> ALL(?::bigint[])', ['{5,9}']], PostgresEpisodeRepository::scope(HistoryScope::all([5, 9])));
        self::assertSame([' AND c.channel = ? AND c.chat_id = ?', ['telegram', '42']], PostgresEpisodeRepository::scope(HistoryScope::chat('telegram', '42')));
    }
}
