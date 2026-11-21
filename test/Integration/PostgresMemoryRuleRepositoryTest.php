<?php

declare(strict_types=1);

namespace OpenCCK\Test\Integration;

use Amp\PHPUnit\AsyncTestCase;
use Amp\Postgres\PostgresConnection;
use Mercurius\Memory\Infrastructure\PostgresAliasStore;
use OpenCCK\Infrastructure\Repository\PostgresMemoryRuleRepository;
use OpenCCK\Test\Support\TestDatabase;

/**
 * The rules of MEMORY.md as last seen and their changes; and the owner's table of words that mean one thing.
 *
 * spec: brief/memory.md#сообщение-об-изменении-правил
 * spec: brief/memory.md#словари
 *
 * @group integration
 */
final class PostgresMemoryRuleRepositoryTest extends AsyncTestCase {
    private PostgresConnection $db;

    protected function setUp(): void {
        parent::setUp();
        $this->db = TestDatabase::connect();
    }

    protected function tearDown(): void {
        $this->db->close();
        parent::tearDown();
    }

    public function testSnapshotsChangesAndForgetting(): void {
        $rules = new PostgresMemoryRuleRepository($this->db);
        self::assertNull($rules->snapshot('1:MEMORY.md'));

        $rules->keep('1:MEMORY.md', '- коротко');
        $rules->keep('1:MEMORY.md', '- подробно');
        $id = $rules->record(1, 'projects/3/MEMORY.md', 'project', 3, '- коротко', '- подробно');

        self::assertSame('- подробно', $rules->snapshot('1:MEMORY.md'));
        self::assertSame(['holder' => 1, 'path' => 'projects/3/MEMORY.md', 'kind' => 'project', 'projectId' => 3, 'before' => '- коротко', 'after' => '- подробно', 'undone' => false], $rules->change($id));
        $rules->undone($id);
        self::assertTrue($rules->change($id)['undone'] ?? false);

        $rules->forget();
        self::assertNull($rules->snapshot('1:MEMORY.md'));
        self::assertNull($rules->change($id));
    }

    public function testTheTableOfWords(): void {
        $aliases = new PostgresAliasStore($this->db);
        $id = $aliases->add(['прокс', 'proxmox'], 1);
        self::assertTrue($aliases->replace($id, ['прокс', 'proxmox', 'pve']));

        self::assertSame([$id => ['прокс', 'proxmox', 'pve']], $aliases->groups());
        self::assertTrue($aliases->remove($id));
        self::assertSame([], $aliases->groups());
    }
}
