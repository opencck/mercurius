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
