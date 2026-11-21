<?php

declare(strict_types=1);

namespace OpenCCK\Test\Integration;

use Amp\PHPUnit\AsyncTestCase;
use OpenCCK\Infrastructure\Repository\PostgresProjectRepository;
use OpenCCK\Infrastructure\Repository\PostgresSecretRepository;
use OpenCCK\Test\Support\TestDatabase;

/**
 * A secret of a project lives apart from a personal one of the same name and goes with its project.
 *
 * spec: brief/participants.md#секреты-проекта
 *
 * @group integration
 */
final class PostgresSecretRepositoryTest extends AsyncTestCase {
    public function testAProjectsSecretAndAPersonalOneOfTheSameNameLiveApart(): void {
        $db = TestDatabase::connect();
        [$principalId] = TestDatabase::conversation($db);
        $project = (new PostgresProjectRepository($db))->create('Секреты ' . bin2hex(random_bytes(4)), '', $principalId);
        $secrets = new PostgresSecretRepository($db);
        $name = 'tok-' . bin2hex(random_bytes(3));

        $personal = $secrets->store($name, 'password', 'n1', 'c1', $principalId);
        $own = $secrets->storeIn($project->id, 'hh.ru', $name, 'password', 'n2', 'c2', $principalId);

        self::assertNotSame($personal, $own);
        self::assertSame('c1', $secrets->find($name)['ciphertext'] ?? null, 'the personal one by its name');
        self::assertSame(['c2', 'hh.ru'], [$secrets->findIn($name, $project->id)['ciphertext'] ?? null, $secrets->findIn($name, $project->id)['site'] ?? null]);
        self::assertSame([['name' => $name, 'kind' => 'password', 'site' => 'hh.ru']], $secrets->ofProject($project->id));
        self::assertCount(1, array_filter($secrets->all(), static fn(array $s): bool => $s['name'] === $name), 'the list of personal secrets has no project one');

        self::assertTrue($secrets->replaceIn($name, $project->id, 'api.hh.ru', 'password', 'n3', 'c3'));
        self::assertSame('api.hh.ru', $secrets->findIn($name, $project->id)['site'] ?? null);
        self::assertTrue($secrets->deleteIn($name, $project->id));
        self::assertNotNull($secrets->find($name), 'the personal one stays');
        $secrets->delete($name);
        $db->execute('DELETE FROM projects WHERE id = ?', [$project->id]);
        $db->close();
    }
}
