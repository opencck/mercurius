<?php

declare(strict_types=1);

namespace OpenCCK\Test\Integration;

use Amp\PHPUnit\AsyncTestCase;
use Amp\Postgres\PostgresConnection;
use DateTimeImmutable;
use OpenCCK\Domain\Agent\RunLimits;
use OpenCCK\Domain\Agent\StopReason;
use OpenCCK\Domain\Entity\LlmCallRecord;
use OpenCCK\Infrastructure\Repository\PostgresLlmCallRepository;
use OpenCCK\Infrastructure\Repository\PostgresRunRepository;
use OpenCCK\Infrastructure\Repository\PostgresTraceReportRepository;
use OpenCCK\Test\Support\TestDatabase;

/**
 * @group integration
 */
final class PostgresTraceReportTest extends AsyncTestCase {
    private PostgresConnection $db;

    protected function setUp(): void {
        parent::setUp();
        $this->db = TestDatabase::connect();
    }

    protected function tearDown(): void {
        $this->db->close();
        parent::tearDown();
    }

    public function testFiguresComeFromCallsAndRuns(): void {
        [$principalId, $conversationId] = TestDatabase::conversation($this->db);
        $run = (new PostgresRunRepository($this->db))->start($conversationId, $principalId, 'openrouter:report/model', new RunLimits(30, 1000, 900, 120));
        self::assertNotNull($run);
        (new PostgresLlmCallRepository($this->db))->record(new LlmCallRecord($conversationId, 'agent', 'openrouter:report/model', 'stop', null, 1000, 100, 200, 0, 0, 0.0021, 900, $run->id));
        $reports = new PostgresTraceReportRepository($this->db);
        $since = new DateTimeImmutable('-1 hour');

        $models = array_column($reports->usageByModel($since), null, 'model');
        self::assertArrayHasKey('openrouter:report/model', $models);
        self::assertSame(1200, $models['openrouter:report/model']['input']);
        self::assertNotSame([], $reports->usageByDay($since));
        self::assertContains($run->id, array_column($reports->costliestRuns($since, 100), 'id'));
        $recent = array_column($reports->recentRuns(1000), null, 'id');
        self::assertEqualsWithDelta(0.0021, $recent[$run->id]['cost'], 0.0000001);
    }

    public function testDelegationFiguresAndModelFailures(): void {
        [$principalId, $conversationId] = TestDatabase::conversation($this->db);
        $runs = new PostgresRunRepository($this->db);
        $conversations = new \OpenCCK\Infrastructure\Repository\PostgresConversationRepository($this->db);
        $orchestrator = $runs->start($conversationId, $principalId, 'openrouter:report/top', new RunLimits(30, 100_000, 900, 120));
        self::assertNotNull($orchestrator);
        $model = 'openrouter:report/worker-' . bin2hex(random_bytes(4));
        $finished = [];
        foreach (['-1' => StopReason::Answered, '-2' => StopReason::ModelFailed, '-2-retry' => StopReason::Answered, '-2-verify' => StopReason::Answered] as $suffix => $reason) {
            $chat = $conversations->openConversation($principalId, 'worker', 'report' . $orchestrator->id . $suffix);
            $worker = $runs->start($chat, $principalId, $model, new RunLimits(15, 10_000, 900, 120), $orchestrator->id);
            self::assertNotNull($worker);
            $runs->finish($worker->id, $reason->status(), $reason);
            $finished[] = $worker->id;
        }
        $since = new DateTimeImmutable('-1 hour');
        $reports = new PostgresTraceReportRepository($this->db);

        $figures = $reports->delegation($since);
        self::assertGreaterThanOrEqual(1, $figures['delegating']);
        self::assertGreaterThanOrEqual(2, $figures['workers']);
        self::assertGreaterThanOrEqual(1, $figures['cascades']);
        self::assertGreaterThanOrEqual(1, $figures['verifiers']);
        $byModel = array_column($reports->workerModels($since), null, 'model');
        self::assertSame(4, $byModel[$model]['runs']);
        self::assertSame(3, $byModel[$model]['answered']);
        self::assertSame(1, $byModel[$model]['failed']);
        $failures = (new \OpenCCK\Infrastructure\Repository\PostgresModelReliabilityRepository($this->db))->failures($since);
        self::assertSame(['runs' => 4, 'failures' => 1], $failures[$model]);
    }
}
