<?php

declare(strict_types=1);

namespace OpenCCK\Test\Integration;

use Amp\PHPUnit\AsyncTestCase;
use Amp\Postgres\PostgresConnection;
use DateTimeImmutable;
use OpenCCK\Domain\Agent\RunLimits;
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
}
