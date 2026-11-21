<?php

declare(strict_types=1);

namespace OpenCCK\Test\Integration;

use Amp\PHPUnit\AsyncTestCase;
use OpenCCK\Infrastructure\Repository\PostgresProjectPrefRepository;
use OpenCCK\Infrastructure\Repository\PostgresProjectRepository;
use OpenCCK\Test\Support\TestDatabase;

/**
 * A project's clock is kept, reset to the environment's and goes with the project.
 *
 * spec: brief/scheduler.md#часовой-пояс-проекта
 *
 * @group integration
 */
final class PostgresProjectPrefRepositoryTest extends AsyncTestCase {
    public function testTheClockOfAProjectIsKeptAndResetToTheEnvironments(): void {
        $db = TestDatabase::connect();
        [$principalId] = TestDatabase::conversation($db);
        $project = (new PostgresProjectRepository($db))->create('Часы ' . bin2hex(random_bytes(4)), '', $principalId);
        $prefs = new PostgresProjectPrefRepository($db);

        self::assertSame(['timezone' => null, 'quiet' => null], $prefs->get($project->id));
        $prefs->save($project->id, 'Asia/Bangkok', '');
        self::assertSame(['timezone' => 'Asia/Bangkok', 'quiet' => ''], $prefs->get($project->id), 'no quiet hours is kept apart from the default');
        $prefs->save($project->id, null, '22-8');
        self::assertSame(['timezone' => null, 'quiet' => '22-8'], $prefs->get($project->id));
        $db->execute('DELETE FROM projects WHERE id = ?', [$project->id]);
        self::assertSame(['timezone' => null, 'quiet' => null], $prefs->get($project->id));
        $db->close();
    }
}
