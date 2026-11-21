<?php

declare(strict_types=1);

namespace OpenCCK\Test\Integration;

use Amp\PHPUnit\AsyncTestCase;
use OpenCCK\Infrastructure\Repository\PostgresProjectPolicyRepository;
use OpenCCK\Infrastructure\Repository\PostgresProjectRepository;
use OpenCCK\Test\Support\TestDatabase;

/**
 * The rights of a project are kept as they were written and go with the project.
 *
 * spec: brief/participants.md#права-проекта
 *
 * @group integration
 */
final class PostgresProjectPolicyRepositoryTest extends AsyncTestCase {
    public function testRightsAreKeptReplacedAndGoWithTheProject(): void {
        $db = TestDatabase::connect();
        [$principalId] = TestDatabase::conversation($db);
        $projects = new PostgresProjectRepository($db);
        $project = $projects->create('Права ' . bin2hex(random_bytes(4)), '', $principalId);
        $repository = new PostgresProjectPolicyRepository($db);

        self::assertNull($repository->find($project->id));
        $repository->save($project->id, ['tools' => ['read_file'], 'max_turns' => 10, 'daily_spend_usd' => 1.5]);
        $repository->save($project->id, ['tools' => ['read_file', 'web_read'], 'max_turns' => 5]);

        self::assertSame(['tools' => ['read_file', 'web_read'], 'max_turns' => 5], $repository->find($project->id));
        self::assertContains($project->id, $repository->projects());
        $db->execute('DELETE FROM projects WHERE id = ?', [$project->id]);
        self::assertNull($repository->find($project->id));
        $db->close();
    }
}
