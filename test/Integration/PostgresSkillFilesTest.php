<?php

declare(strict_types=1);

namespace OpenCCK\Test\Integration;

use Amp\PHPUnit\AsyncTestCase;
use OpenCCK\Infrastructure\Repository\PostgresSkillRepository;
use OpenCCK\Test\Support\TestDatabase;

/**
 * Files of a skill belong to its version: only the active version's files are read, a rollback brings them back.
 *
 * @group integration
 */
final class PostgresSkillFilesTest extends AsyncTestCase {
    public function testFilesFollowTheActiveVersion(): void {
        $db = TestDatabase::connect();
        $skills = new PostgresSkillRepository($db);
        $first = $skills->propose('pdf', 'PDF', 'v1', 'import', null, 'external');
        $skills->attachFiles($first->id, ['scripts/fill.py' => 'print(1)', 'references/a.md' => '# A']);

        self::assertSame([], $skills->files('pdf'), 'a draft has no files to read');
        $skills->accept($first->id);
        self::assertSame(['references/a.md' => '# A', 'scripts/fill.py' => 'print(1)'], $skills->files('pdf'));

        $second = $skills->propose('pdf', 'PDF', 'v2', 'update', null, 'external');
        $skills->accept($second->id);
        self::assertSame([], $skills->files('pdf'));
        $skills->rollback('pdf', 1);
        self::assertSame(['references/a.md' => '# A', 'scripts/fill.py' => 'print(1)'], $skills->files('pdf'));
        self::assertSame(['references/a.md' => '# A', 'scripts/fill.py' => 'print(1)'], $skills->versionFiles($first->id));
        $db->close();
    }
}
