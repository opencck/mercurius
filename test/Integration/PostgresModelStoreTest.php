<?php

declare(strict_types=1);

namespace OpenCCK\Test\Integration;

use Amp\Postgres\PostgresConnection;
use OpenCCK\Infrastructure\Repository\PostgresModelCatalogRepository;
use OpenCCK\Infrastructure\Repository\PostgresModelRoleRepository;
use OpenCCK\Infrastructure\Repository\PostgresRoutingDecisionRepository;
use OpenCCK\Test\Support\ModelStoreContract;
use OpenCCK\Test\Support\TestDatabase;

/**
 * @group integration
 */
final class PostgresModelStoreTest extends ModelStoreContract {
    private PostgresConnection $db;

    protected function setUp(): void {
        parent::setUp();
        $this->db = TestDatabase::connect();
        // One role table for the whole agent: each test starts from an empty one.
        $this->db->query('TRUNCATE model_roles');
        $this->catalog = new PostgresModelCatalogRepository($this->db);
        $this->roles = new PostgresModelRoleRepository($this->db);
        $this->decisions = new PostgresRoutingDecisionRepository($this->db);
    }

    public function testModelsThatCallToolsBadlyAreMarkedAfterTheCatalog(): void {
        // spec: BRIEF.md#выбор-моделей-под-задачу
        $info = static fn(string $id): \Mercurius\Llm\ModelCatalog\ModelInfo => new \Mercurius\Llm\ModelCatalog\ModelInfo($id, $id, 1000, 0.0, 0.0, ['text'], ['text'], ['tools']);
        $this->catalog->replace('toolrel', [$info('a/good'), $info('b/"odd"'), $info('c/bad')]);
        self::assertSame([], array_values(array_filter($this->catalog->toolUnreliable(), static fn(string $r): bool => str_starts_with($r, 'toolrel:'))), 'not known yet');

        $this->catalog->markToolReliable('toolrel', ['a/good', 'b/"odd"']);

        self::assertSame(['toolrel:c/bad'], array_values(array_filter($this->catalog->toolUnreliable(), static fn(string $r): bool => str_starts_with($r, 'toolrel:'))));
        $this->catalog->replace('toolrel', []);
    }

    public function testARunTakesTheDecisionAboutItsModelMadeBeforeItStarted(): void {
        // spec: BRIEF.md#выбор-моделей-под-задачу
        [$principalId, $conversationId] = \OpenCCK\Test\Support\TestDatabase::conversation($this->db, 'routing-' . bin2hex(random_bytes(4)));
        $runs = new \OpenCCK\Infrastructure\Repository\PostgresRunRepository($this->db);
        $limits = new \OpenCCK\Domain\Agent\RunLimits(30, 1000, 900, 120);
        $this->decisions->record(new \OpenCCK\Domain\Agent\RoutingDecision($conversationId, 'complex', 'heuristic', 'strong', 'openrouter:a/strong', 'long task'));
        $run = $runs->start($conversationId, $principalId, 'openrouter:a/strong', $limits);
        self::assertNotNull($run);
        $this->decisions->record(new \OpenCCK\Domain\Agent\RoutingDecision($conversationId, 'fallback', 'failure', 'fallback', 'openrouter:a/other', 'down', $run->id));
        $this->decisions->record(new \OpenCCK\Domain\Agent\RoutingDecision($conversationId, 'profile', 'profile', 'main', 'openrouter:a/worker', 'needs code'));
        [, $workerConversation] = \OpenCCK\Test\Support\TestDatabase::conversation($this->db, 'worker-' . bin2hex(random_bytes(4)));
        $worker = $runs->start($workerConversation, $principalId, 'openrouter:a/worker', $limits, $run->id);

        $taken = [];
        foreach ($this->db->execute('SELECT method, run_id FROM routing_decisions WHERE conversation_id = ? ORDER BY id', [$conversationId]) as $row) {
            $taken[] = [$row['method'] ?? null, $row['run_id'] ?? null];
        }
        self::assertSame([['heuristic', $run->id], ['failure', $run->id], ['profile', $worker?->id]], $taken);
    }

    protected function tearDown(): void {
        $this->db->close();
        parent::tearDown();
    }
}
