<?php

declare(strict_types=1);

namespace OpenCCK\Test\Integration;

use Amp\PHPUnit\AsyncTestCase;
use OpenCCK\Infrastructure\Repository\PostgresSkillUsageRepository;
use OpenCCK\Test\Support\TestDatabase;

/**
 * @group integration
 */
final class PostgresSkillUsageTest extends AsyncTestCase {
    public function testASkillIsOpenedPinnedAndTurnedOff(): void {
        $db = TestDatabase::connect();
        $usage = new PostgresSkillUsageRepository($db);
        $name = 'test-' . bin2hex(random_bytes(4));

        $usage->touch($name);
        $usage->pin($name, true);
        $usage->turnOff($name, true);
        $stat = $usage->stats()[$name] ?? null;
        self::assertSame([1, true, true], [$stat['views'] ?? null, $stat['pinned'] ?? null, $stat['off'] ?? null]);

        $usage->turnOff($name, false);
        self::assertFalse($usage->stats()[$name]['off'] ?? true);
        $db->execute('DELETE FROM skill_usage WHERE name = ?', [$name]);
        $db->close();
    }
}
