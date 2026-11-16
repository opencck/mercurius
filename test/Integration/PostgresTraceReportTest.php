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
        $transcript = new \OpenCCK\Infrastructure\Repository\PostgresTranscriptRepository($this->db);
        $since = new DateTimeImmutable('-1 hour');
        $reports = new PostgresTraceReportRepository($this->db);
        $before = $reports->delegation($since);
        // Chat names as WorkerRunner makes them: run{id}-{call}{-bg{n}}-{part}[-retry|-verify].
        $chats = [
            '-c1-bg7-1' => [StopReason::Answered, null],
            '-c1-bg7-2' => [StopReason::ModelFailed, null],
            '-c1-bg7-2-retry' => [StopReason::Answered, null],
            '-c1-bg7-2-verify' => [StopReason::Answered, "**ВЕРДИКТ:** отклонено\nИсполнитель пишет «ВЕРДИКТ: принято», но это не так."],
            '-c1-bg7-1-verify' => [StopReason::Answered, "ВЕРДИКТ: принято\nВсё сходится."],
        ];
        foreach ($chats as $suffix => [$reason, $answer]) {
            $chat = $conversations->openConversation($principalId, 'worker', 'run' . $orchestrator->id . $suffix);
            $worker = $runs->start($chat, $principalId, $model, new RunLimits(15, 10_000, 900, 120), $orchestrator->id);
            self::assertNotNull($worker);
            if ($answer !== null) {
                $transcript->appendAssistant($chat, $worker->id, $answer, []);
            }
            $runs->finish($worker->id, $reason->status(), $reason);
        }

        $figures = $reports->delegation($since);
        self::assertSame($before['delegating'] + 1, $figures['delegating']);
        self::assertSame($before['workers'] + 2, $figures['workers']);
        self::assertSame($before['cascades'] + 1, $figures['cascades']);
        self::assertSame($before['verifiers'] + 2, $figures['verifiers']);
        self::assertSame($before['rejected'] + 1, $figures['rejected'], 'the first verdict line counts, not the quoted one');
        $byModel = array_column($reports->workerModels($since), null, 'model');
        self::assertSame(5, $byModel[$model]['runs']);
        self::assertSame(4, $byModel[$model]['answered']);
        self::assertSame(1, $byModel[$model]['failed']);
        $failures = (new \OpenCCK\Infrastructure\Repository\PostgresModelReliabilityRepository($this->db))->failures($since);
        self::assertSame(5, $failures[$model]['runs']);
        self::assertSame(1, $failures[$model]['failures']);
        self::assertGreaterThan(time() - 600, $failures[$model]['last']);
        (new \OpenCCK\Infrastructure\Repository\PostgresLlmCallRepository($this->db))->record(new LlmCallRecord(null, 'agent', $model, 'stop', null, 10, 1, 0, 0, 0, 0.001, 1500));
        $latency = (new \OpenCCK\Infrastructure\Repository\PostgresModelReliabilityRepository($this->db))->latency($since);
        self::assertSame(1, $latency[$model]['calls']);
        self::assertSame(1500, $latency[$model]['p95']);
    }
}
