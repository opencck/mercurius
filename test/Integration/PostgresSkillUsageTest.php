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

        $usage->touch('1:.mercurius/skills', $name);
        $usage->pin('1:.mercurius/skills', $name, true);
        $usage->turnOff('1:.mercurius/skills', $name, true);
        $usage->created('5:.mercurius/skills', $name);
        $stats = $usage->stats();
        $stat = $stats['1:.mercurius/skills'][$name] ?? null;
        self::assertSame([1, true, true], [$stat['views'] ?? null, $stat['pinned'] ?? null, $stat['off'] ?? null]);
        $other = $stats['5:.mercurius/skills'][$name] ?? null;
        self::assertNotNull($other);
        self::assertSame(0, $other['views'], 'the same name in another place is another skill');
        self::assertGreaterThan(time() - 60, $other['created']);

        $usage->turnOff('1:.mercurius/skills', $name, false);
        self::assertFalse($usage->stats()['1:.mercurius/skills'][$name]['off'] ?? true);
        $db->execute('DELETE FROM skill_stats WHERE name = ?', [$name]);
        $db->close();
    }

    public function testARowOfTheTimeBeforePlacesIsClaimedOnce(): void {
        $db = TestDatabase::connect();
        $usage = new PostgresSkillUsageRepository($db);
        $name = 'test-' . bin2hex(random_bytes(4));
        $db->execute("INSERT INTO skill_stats (place, name, views, pinned) VALUES ('', ?, 4, true)", [$name]);

        $usage->touch('1:.mercurius/skills', $name);
        $usage->touch('5:.mercurius/skills', $name);

        $stats = $usage->stats();
        self::assertSame([5, true], [$stats['1:.mercurius/skills'][$name]['views'] ?? null, $stats['1:.mercurius/skills'][$name]['pinned'] ?? null]);
        self::assertSame(1, $stats['5:.mercurius/skills'][$name]['views'] ?? null);
        self::assertArrayNotHasKey($name, $stats[''] ?? []);
        $db->execute('DELETE FROM skill_stats WHERE name = ?', [$name]);
        $db->close();
    }
    public function testTheJournalOfASkillKeepsWhatWasThere(): void {
        $db = TestDatabase::connect();
        $edits = new \OpenCCK\Infrastructure\Repository\PostgresSkillEditRepository($db);
        $dir = '.mercurius/skills/test-' . bin2hex(random_bytes(4));

        $first = $edits->record(1, $dir, 'notes', null, 'skill_save');
        $second = $edits->record(1, $dir, 'notes', ['SKILL.md' => "Первая версия «с кавычками»", 'refs/a.md' => 'x'], 'skill_save');

        self::assertSame([$second, $first], array_column($edits->forSkill(1, $dir, 10), 'id'));
        self::assertTrue($edits->forSkill(1, $dir, 10)[1]['created'] ?? false);
        self::assertSame(['SKILL.md' => "Первая версия «с кавычками»", 'refs/a.md' => 'x'], $edits->find($second)['before'] ?? null);
        $made = $edits->find($first);
        self::assertNotNull($made);
        self::assertNull($made['before']);
        $db->execute('DELETE FROM skill_edits WHERE dir = ?', [$dir]);
        $db->close();
    }
}
