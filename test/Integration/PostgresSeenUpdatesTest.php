<?php

declare(strict_types=1);

namespace OpenCCK\Test\Integration;

use Amp\PHPUnit\AsyncTestCase;
use Mercurius\Messenger\Deduplicator;
use OpenCCK\Infrastructure\Repository\PostgresSeenUpdates;
use OpenCCK\Test\Support\TestDatabase;

/**
 * spec: BRIEF.md#канал-необязательные-возможности-и-разрезанные-тексты — an update redelivered after a restart is not
 * taken twice
 *
 * @group integration
 */
final class PostgresSeenUpdatesTest extends AsyncTestCase {
    public function testAnUpdateSeenBeforeARestartIsADuplicate(): void {
        $db = TestDatabase::connect();
        $seen = new PostgresSeenUpdates($db);
        $key = 'telegram:test-' . bin2hex(random_bytes(4));

        self::assertFalse((new Deduplicator(firstSeen: $seen->fresh(...)))->isDuplicate($key));
        self::assertTrue((new Deduplicator(firstSeen: $seen->fresh(...)))->isDuplicate($key), 'a new process still knows it');

        $db->execute("UPDATE seen_updates SET seen_at = now() - interval '2 days' WHERE key = ?", [$key]);
        self::assertGreaterThanOrEqual(1, $seen->prune());
        self::assertTrue($seen->fresh($key), 'forgotten after a day');
        $db->execute('DELETE FROM seen_updates WHERE key = ?', [$key]);
        $db->close();
    }
}
