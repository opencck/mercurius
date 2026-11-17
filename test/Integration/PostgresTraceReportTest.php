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

    public function testSpendIsSplitByTopicProjectParticipantAndDay(): void {
        $chat = '-100' . random_int(100000, 999999);
        [$owner, $general] = TestDatabase::conversation($this->db, $chat);
        $topicRow = $this->db->execute("INSERT INTO conversations (principal_id, channel, chat_id, thread_id) VALUES (?, 'test', ?, '77') RETURNING id", [$owner, $chat])->fetchRow();
        $topic = $topicRow['id'] ?? null;
        self::assertIsInt($topic);
        $projectRow = $this->db->execute("INSERT INTO projects (name) VALUES (?) RETURNING id", ['OpenWRT ' . $chat])->fetchRow();
        $this->db->execute("INSERT INTO chat_projects (channel, chat_id, thread_id, project_id) VALUES ('test', ?, '77', ?)", [$chat, $projectRow['id'] ?? 0]);
        $this->db->execute("INSERT INTO chat_groups (channel, chat_id, title) VALUES ('test', ?, 'Mercurius')", [$chat]);
        $guestRow = $this->db->execute("INSERT INTO principals (kind, role, display_name) VALUES ('human', 'user', 'Артур') RETURNING id")->fetchRow();
        $guest = $guestRow['id'] ?? null;
        self::assertIsInt($guest);
        $runs = new PostgresRunRepository($this->db);
        $calls = new PostgresLlmCallRepository($this->db);
        $inTopic = $runs->start($topic, $guest, 'm', new RunLimits(30, 1000, 900, 120));
        $inGeneral = $runs->start($general, $owner, 'm', new RunLimits(30, 1000, 900, 120));
        self::assertNotNull($inTopic);
        self::assertNotNull($inGeneral);
        $calls->record(new LlmCallRecord($topic, 'agent', 'm/a', 'stop', null, 1000, 100, 0, 0, 0, 0.03, 10, $inTopic->id));
        $calls->record(new LlmCallRecord($topic, 'agent', 'm/a', 'stop', null, 500, 50, 0, 0, 0, 0.01, 10, $inTopic->id));
        $calls->record(new LlmCallRecord($general, 'agent', 'm/b', 'stop', null, 200, 20, 0, 0, 0, 0.005, 10, $inGeneral->id));
        $reports = new PostgresTraceReportRepository($this->db);
        $zone = new \DateTimeZone('Europe/Moscow');
        $from = new DateTimeImmutable('-1 day', $zone);
        $to = new DateTimeImmutable('+1 day', $zone);
        $here = ['test', $chat, ''];

        $topics = $reports->breakdown(new \OpenCCK\Domain\Agent\SpendQuery('topic', $from, $to, null, $here));
        self::assertCount(2, $topics);
        self::assertSame(['77', 'OpenWRT ' . $chat, 'Mercurius', 2, 1650], [$topics[0]['thread'], $topics[0]['project'], $topics[0]['chat'], $topics[0]['calls'], $topics[0]['input'] + $topics[0]['output']]);
        self::assertEqualsWithDelta(0.04, $topics[0]['cost'], 0.000001);
        self::assertSame('', $topics[1]['thread'], 'the general topic apart');

        $people = $reports->breakdown(new \OpenCCK\Domain\Agent\SpendQuery('participant', $from, $to, null, $here));
        self::assertSame('Артур', $people[0]['person'], 'a call belongs to the participant of its run');

        $mine = $reports->breakdown(new \OpenCCK\Domain\Agent\SpendQuery('project', $from, $to, $guest, $here));
        self::assertCount(1, $mine);
        self::assertSame('OpenWRT ' . $chat, $mine[0]['project']);

        $onlyTopic = $reports->breakdown(new \OpenCCK\Domain\Agent\SpendQuery('day', $from, $to, null, ['test', $chat, '77']));
        self::assertSame((new DateTimeImmutable('now', $zone))->format('Y-m-d'), $onlyTopic[0]['label']);
        self::assertSame(2, $onlyTopic[0]['calls']);

        self::assertSame([], $reports->breakdown(new \OpenCCK\Domain\Agent\SpendQuery('model', $to, $to->modify('+1 day'), null, $here)), 'outside the period');
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
