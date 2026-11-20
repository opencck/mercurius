<?php

declare(strict_types=1);

namespace OpenCCK\Test\Integration;

use Amp\PHPUnit\AsyncTestCase;
use OpenCCK\Infrastructure\Database\MigrationSet;
use OpenCCK\Infrastructure\Database\Migrator;
use OpenCCK\Infrastructure\Repository\PostgresPrincipalRepository;
use OpenCCK\Infrastructure\Repository\PostgresProjectRepository;
use OpenCCK\Test\Support\TestDatabase;
use Psr\Log\NullLogger;

/**
 * An installation of an earlier release is updated: its base has the migrations of that release and data in it;
 * the new migrations apply over it, and the participants, conversations, memory and projects are still there and read
 * by the code of the new release.
 *
 * spec: BRIEF.md#решено-в-фазе-12 — an update keeps the data and the memory; migrations only add to the schema
 *
 * @group integration
 */
final class UpgradeTest extends AsyncTestCase {
    /** The last migration of the release before 2026-11-18 (installs of 2026-11-17 stopped at it). */
    private const PREVIOUS = 40;

    public function testAnUpdateKeepsTheDataOfThePreviousRelease(): void {
        $db = TestDatabase::connect(false);
        $old = [];
        $all = [];
        foreach (\OpenCCK\Infrastructure\Database\MigrationFolders::of(dirname(__DIR__, 2)) as $directory) {
            $names = scandir($directory);
            foreach ($names === false ? [] : $names as $name) {
                if (!str_ends_with($name, '.sql')) {
                    continue;
                }
                $sql = (string) file_get_contents($directory . '/' . $name);
                $all[$name] = $sql;
                if ((int) $name <= self::PREVIOUS) {
                    $old[$name] = $sql;
                }
            }
        }
        $migrator = new Migrator($db, new NullLogger());
        $migrator->migrate(MigrationSet::fromFiles($old));

        // what an owner of the previous release had
        $owner = $db->query("INSERT INTO principals (kind, role, display_name) VALUES ('human', 'owner', 'Владелец') RETURNING id")->fetchRow()['id'] ?? null;
        self::assertIsInt($owner);
        $db->execute("INSERT INTO channel_identities (channel, external_id, principal_id) VALUES ('telegram', '42', ?)", [$owner]);
        $conversation = $db->execute("INSERT INTO conversations (principal_id, channel, chat_id) VALUES (?, 'telegram', '42') RETURNING id", [$owner])->fetchRow()['id'] ?? null;
        self::assertIsInt($conversation);
        $db->execute("INSERT INTO messages (conversation_id, role, content) VALUES (?, 'user', 'Привет'), (?, 'assistant', 'Здравствуйте')", [$conversation, $conversation]);
        $db->execute("INSERT INTO facts (scope, text, keys, provenance) VALUES ('owner', 'Владелец живёт в Паттайе', ARRAY['паттай'], 'owner')");
        $db->execute("INSERT INTO projects (name, goal, card) VALUES ('Меркурий', 'Агент на сервере', 'PHP 8.2')");

        $applied = $migrator->migrate(MigrationSet::fromFiles($all));
        self::assertNotSame([], $applied, 'the new release brings migrations');
        self::assertContains(50, $applied, 'up to the newest migration');
        self::assertNotContains(self::PREVIOUS, $applied, 'the old ones are not applied again');

        $principals = new PostgresPrincipalRepository($db, 'telegram');
        self::assertSame($owner, $principals->findByIdentity('telegram', '42')?->id, 'the owner is the owner');
        self::assertSame('Владелец', $principals->find($owner)?->displayName);
        $project = (new PostgresProjectRepository($db))->byName('Меркурий');
        self::assertNotNull($project);
        self::assertSame('PHP 8.2', $project->card);
        self::assertSame('', $project->skill, 'a new column has its default');
        self::assertNull($project->ownerId, 'old projects stay the owner\'s');
        self::assertSame(2, $db->execute('SELECT count(*) AS n FROM messages WHERE conversation_id = ?', [$conversation])->fetchRow()['n'] ?? null);
        self::assertSame('Владелец живёт в Паттайе', $db->query("SELECT text FROM facts WHERE scope = 'owner'")->fetchRow()['text'] ?? null);

        self::assertSame([], $migrator->migrate(MigrationSet::fromFiles($all)), 'a second start applies nothing');
    }
}
