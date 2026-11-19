<?php

declare(strict_types=1);

namespace OpenCCK\Test\Integration;

use Amp\Postgres\PostgresConnection;
use OpenCCK\Infrastructure\Repository\PostgresPrincipalRepository;
use OpenCCK\Test\Support\PrincipalStoreContract;
use OpenCCK\Test\Support\TestDatabase;

/**
 * @group integration
 */
final class PostgresPrincipalStoreTest extends PrincipalStoreContract {
    private PostgresConnection $db;

    protected function setUp(): void {
        parent::setUp();
        $this->db = TestDatabase::connect();
        $this->principals = new PostgresPrincipalRepository($this->db, 'telegram');
        $this->externalId = 'visitor-' . bin2hex(random_bytes(6));
    }

    public function testThePersonalStaysWithTheFormerOwnerAndTheAgentGoesOn(): void {
        $owner = $this->principals->ensureOwner('telegram', 'owner-' . $this->externalId, 'Владелец');
        $next = $this->principals->createVisitor('telegram', $this->externalId, 'Преемник');
        $this->principals->decideVisitor($next->id, \OpenCCK\Domain\Entity\Role::user(), \OpenCCK\Domain\Entity\PrincipalStatus::Active);
        $mark = 'transfer-' . $this->externalId;
        $fact = $this->db->execute("INSERT INTO facts (scope, text, keys, provenance) VALUES ('owner', ?, ARRAY['x'], 'owner_said') RETURNING id", [$mark])->fetchRow()['id'] ?? null;
        $project = $this->db->execute('INSERT INTO projects (name) VALUES (?) RETURNING id', [$mark])->fetchRow()['id'] ?? null;
        $job = $this->db->execute("INSERT INTO jobs (principal_id, channel, chat_id, title, prompt, schedule) VALUES (?, 'telegram', 'old', 'initiative', 'p', 'every:60') RETURNING id", [$owner->id])->fetchRow()['id'] ?? null;
        $own = $this->db->execute("INSERT INTO jobs (principal_id, channel, chat_id, title, prompt, schedule) VALUES (?, 'telegram', 'old', ?, 'p', 'every:60') RETURNING id", [$owner->id, $mark])->fetchRow()['id'] ?? null;
        try {
            self::assertTrue($this->principals->transferOwnership($owner->id, $next->id));

            $scope = $this->db->execute('SELECT scope FROM facts WHERE id = ?', [$fact])->fetchRow()['scope'] ?? null;
            self::assertSame('principal:' . $owner->id, $scope, 'memory about the former owner stays theirs');
            self::assertSame($owner->id, $this->db->execute('SELECT owner_id FROM projects WHERE id = ?', [$project])->fetchRow()['owner_id'] ?? null);
            $moved = $this->db->execute('SELECT principal_id, chat_id FROM jobs WHERE id = ?', [$job])->fetchRow();
            self::assertSame([$next->id, $this->externalId], [$moved['principal_id'] ?? null, $moved['chat_id'] ?? null], 'the job of the agent reports to the new owner');
            self::assertSame($owner->id, $this->db->execute('SELECT principal_id FROM jobs WHERE id = ?', [$own])->fetchRow()['principal_id'] ?? null);
        } finally {
            $this->principals->transferOwnership($next->id, $owner->id);
            $this->db->execute('DELETE FROM facts WHERE id = ?', [$fact]);
            $this->db->execute('DELETE FROM projects WHERE id = ?', [$project]);
            $this->db->execute('DELETE FROM jobs WHERE id IN (?, ?)', [$job, $own]);
        }
    }

    protected function tearDown(): void {
        $this->db->close();
        parent::tearDown();
    }
}
