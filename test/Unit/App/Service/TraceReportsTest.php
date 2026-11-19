<?php

declare(strict_types=1);

namespace OpenCCK\Test\Unit\App\Service;

use Amp\PHPUnit\AsyncTestCase;
use DateTimeImmutable;
use OpenCCK\App\Service\ChatService;
use OpenCCK\App\Service\TraceReports;
use OpenCCK\Domain\Agent\TraceSpan;
use OpenCCK\Domain\Entity\Power;
use OpenCCK\Domain\Entity\Principal;
use OpenCCK\Domain\Entity\PrincipalKind;
use OpenCCK\Domain\Entity\PrincipalStatus;
use OpenCCK\Domain\Entity\Role;
use OpenCCK\Test\Support\AgentStack;
use OpenCCK\Test\Support\FakeTraceReports;
use OpenCCK\Test\Support\InMemoryTraceRepository;

/**
 * Spend and run reports for the owner: /usage, /runs, /trace.
 */
final class TraceReportsTest extends AsyncTestCase {
    private FakeTraceReports $figures;
    private InMemoryTraceRepository $traces;
    private AgentStack $stack;

    protected function setUp(): void {
        parent::setUp();
        $this->figures = new FakeTraceReports(
            models: [
                ['model' => 'openrouter:anthropic/claude-opus-5', 'calls' => 3, 'input' => 9000, 'output' => 2100, 'cost' => 0.0975],
                ['model' => 'openrouter:z-ai/glm-5.3-flash', 'calls' => 40, 'input' => 120000, 'output' => 8000, 'cost' => 0.0221],
            ],
            days: [['day' => '2026-11-16', 'calls' => 43, 'cost' => 0.1196]],
            costliest: [['id' => 42, 'cost' => 0.0305, 'tokens' => 3391]],
            runs: [['id' => 42, 'status' => 'done', 'reason' => 'answered', 'model' => 'openrouter:anthropic/claude-opus-5', 'turns' => 1, 'tokens' => 3391, 'active_ms' => 21000, 'cost' => 0.0305, 'started' => '2026-11-16 19:04']],
        );
        $this->traces = new InMemoryTraceRepository();
        $this->stack = new AgentStack(reports: new TraceReports($this->figures, $this->traces));
        $this->stack->principals->ensureOwner('telegram', '42', 'Owner');
    }

    private function lastText(): string {
        $texts = $this->stack->channel->texts('send');
        self::assertNotSame([], $texts);
        return (string) end($texts);
    }

    public function testUsageShowsModelsDaysAndTotals(): void {
        $this->stack->say('/usage 3');

        $text = $this->lastText();
        self::assertStringContainsString('за 3 дн.', $text);
        self::assertStringContainsString('openrouter:anthropic/claude-opus-5', $text);
        self::assertStringContainsString('$0.0975', $text);
        self::assertStringContainsString('2026-11-16', $text);
        self::assertStringContainsString('Итого: $0.1196', $text);
        self::assertStringContainsString('#42', $text);
        self::assertEqualsWithDelta((new DateTimeImmutable('-3 days'))->getTimestamp(), $this->figures->since?->getTimestamp(), 5);
        self::assertSame([], $this->stack->llm->requests);
    }

    public function testDelegationShowsHowTheAgentSplitsWork(): void {
        $this->figures->delegation = ['top' => 20, 'delegating' => 5, 'workers' => 12, 'cascades' => 2, 'verifiers' => 3, 'rejected' => 1, 'background' => 4];
        $this->figures->workerModels = [['model' => 'openrouter:openai/gpt-5.6-luna', 'runs' => 10, 'answered' => 9, 'failed' => 1, 'tokens' => 15000, 'cost' => 0.0123, 'active_ms' => 4200]];

        $this->stack->say('/delegation 7');

        $text = $this->lastText();
        self::assertStringContainsString('Делегирование за 7 дн.', $text);
        self::assertStringContainsString('с делегированием: 5 (25%)', $text);
        self::assertStringContainsString('повторов каскада: 2, фоновых делегирований: 4', $text);
        self::assertStringContainsString('Проверяющих: 3, отклонили ответ: 1', $text);
        self::assertStringContainsString('openrouter:openai/gpt-5.6-luna — 10, ответили 90%, отказы модели 1, токенов 15 000, $0.0123, в среднем 4.2 с', $text);
    }

    public function testUsageAndDelegationAreSplitByThePromptVersion(): void {
        // spec: BRIEF.md#трассировка-и-метрики — /usage and /delegation by prompt_version
        $this->figures->usageVersions = [['version' => 3, 'runs' => 8, 'calls' => 30, 'cost' => 0.5], ['version' => null, 'runs' => 2, 'calls' => 4, 'cost' => 0.01]];
        $this->figures->delegationVersions = [['version' => 3, 'top' => 8, 'delegating' => 2, 'workers' => 5, 'cost' => 0.2]];

        $this->stack->say('/usage 7');
        $usage = $this->lastText();
        $this->stack->say('/delegation 7');
        $delegation = $this->lastText();

        self::assertStringContainsString(\Mercurius\I18n\t('service.trace_reports.by_prompt_version'), $usage);
        self::assertStringContainsString(sprintf(\Mercurius\I18n\t('service.trace_reports.usage_version_row'), 'v3', '$0.5000', 8, 30), $usage);
        self::assertStringContainsString(sprintf(\Mercurius\I18n\t('service.trace_reports.usage_version_row'), \Mercurius\I18n\t('service.trace_reports.no_version'), '$0.0100', 2, 4), $usage);
        self::assertStringContainsString(sprintf(\Mercurius\I18n\t('service.trace_reports.delegation_version_row'), 'v3', 8, 2, '25%', 5, '$0.2000'), $delegation);
    }

    public function testUsageShowsToolsAndTheLongestStepsAndDelegationTheOrchestratorsShare(): void {
        // spec: BRIEF.md#трассировка-и-метрики — tools, the longest steps, the orchestrator's share of tokens
        $this->figures->tools = [['name' => 'web_read', 'calls' => 12, 'errors' => 3, 'avg_ms' => 2400]];
        $this->figures->longest = [['run' => 7, 'kind' => 'tool', 'name' => 'browser', 'ms' => 95_000]];
        $this->figures->delegationTokens = ['orchestrator' => 30_000, 'workers' => 90_000];

        $this->stack->say('/usage 7');
        $usage = $this->lastText();
        $this->stack->say('/delegation 7');
        $delegation = $this->lastText();

        self::assertStringContainsString(\Mercurius\I18n\t('service.trace_reports.tools_head'), $usage);
        self::assertStringContainsString(sprintf(\Mercurius\I18n\t('service.trace_reports.tool_row'), 'web_read', 12, 3, '25%', '2.4 с'), $usage);
        self::assertStringContainsString(sprintf(\Mercurius\I18n\t('service.trace_reports.longest_row'), 'tool', 'browser', '95 с', 7), $usage);
        self::assertStringContainsString(sprintf(\Mercurius\I18n\t('service.trace_reports.orchestrator_share'), '25%', '30 000', '90 000'), $delegation);
    }

    public function testRunsTakeACountAndAStatus(): void {
        // spec: BRIEF.md#трассировка-и-метрики — /runs <count> <status>
        $this->stack->say('/runs 3 failed');
        $this->lastText();

        self::assertSame([3, 'failed'], [$this->figures->runsAsked[0] ?? null, $this->figures->runsAsked[1] ?? null]);

        $this->stack->say('/runs 500');
        $this->lastText();
        self::assertSame([50, null], $this->figures->runsAsked, 'at most 50');
    }

    public function testAStepGivesItsPromptAndAnswerAsAFile(): void {
        // spec: BRIEF.md#трассировка-и-метрики — /trace <run> <step>: the payload of the step
        $run = $this->traces->openRun(42, 'openrouter:x/model');
        $this->traces->record(new TraceSpan(42, $run, TraceSpan::TOOL, 'web_read', new DateTimeImmutable(), 300, TraceSpan::OK));
        $model = $this->traces->record(new TraceSpan(42, $run, TraceSpan::MODEL, 'openrouter:x/model', new DateTimeImmutable(), 1200, TraceSpan::OK));
        $this->traces->payload($model, ['messages' => [['role' => 'user', 'content' => 'вопрос']]], ['text' => 'ответ']);
        $this->traces->closeRun(42, TraceSpan::OK, 1600, null);

        $this->stack->say('/trace 42');
        $trace = $this->lastText();
        $this->stack->say('/trace 42 2');
        $file = array_slice($this->stack->channel->files, -1)[0] ?? null;
        $this->stack->say('/trace 42 1');
        $none = $this->lastText();

        self::assertStringContainsString('1. tool web_read', $trace, 'the steps are numbered');
        self::assertStringContainsString('2. model openrouter:x/model', $trace);
        self::assertNotNull($file);
        self::assertSame('trace-42-2.json', $file[1]);
        self::assertStringContainsString('вопрос', $file[2]);
        self::assertStringContainsString('ответ', $file[2]);
        self::assertSame(\Mercurius\I18n\t('service.trace_reports.no_payload'), $none);
    }

    public function testRunsListsTheLatest(): void {
        $this->stack->say('/runs');

        $text = $this->lastText();
        self::assertStringContainsString('#42', $text);
        self::assertStringContainsString('21 с', $text);
        self::assertStringContainsString('$0.0305', $text);
    }

    public function testTraceShowsTheChainOfSteps(): void {
        $run = $this->traces->openRun(42, 'openrouter:x/model');
        $this->traces->record(new TraceSpan(42, $run, TraceSpan::MODEL, 'openrouter:x/model', new DateTimeImmutable(), 1200, TraceSpan::OK, 1000, 100, 200, costUsd: 0.002, costStatus: TraceSpan::COST_ACTUAL));
        $this->traces->record(new TraceSpan(42, $run, TraceSpan::TOOL, 'run_command', new DateTimeImmutable(), 300, TraceSpan::ERROR, error: 'exit 1'));
        $this->traces->closeRun(42, TraceSpan::OK, 1600, null);

        $this->stack->say('/trace 42');

        $text = $this->lastText();
        self::assertStringContainsString('Запуск #42', $text);
        self::assertStringContainsString('model openrouter:x/model', $text);
        self::assertStringContainsString('1.2 с', $text);
        self::assertStringContainsString('вход 1000 (кеш 200), выход 100', $text);
        self::assertStringContainsString('tool run_command', $text);
        self::assertStringContainsString('exit 1', $text);
    }

    public function testTheStepsOfAWorkerGoUnderItsStep(): void {
        // spec: BRIEF.md#трассировка-и-метрики — nested traces
        $run = $this->traces->openRun(42, 'openrouter:x/top');
        $this->traces->record(new TraceSpan(42, $run, TraceSpan::WORKER, '#43', new DateTimeImmutable(), 2500, TraceSpan::OK, attrs: ['run' => 43, 'model' => 'openrouter:x/worker']));
        $this->traces->closeRun(42, TraceSpan::OK, 3000, null);
        $worker = $this->traces->openRun(43, 'openrouter:x/worker');
        $this->traces->record(new TraceSpan(43, $worker, TraceSpan::TOOL, 'web_read', new DateTimeImmutable(), 800, TraceSpan::OK));
        $this->traces->closeRun(43, TraceSpan::OK, 2500, null);

        $this->stack->say('/trace 42');

        $text = $this->lastText();
        self::assertStringContainsString('worker #43', $text);
        self::assertMatchesRegularExpression('/\n\s{4,}tool web_read/u', $text, 'the worker\'s steps are indented under it');
    }

    public function testTheTraceOfARunOfAFormerOwnerIsNotShown(): void {
        $this->traces->openRun(43, 'openrouter:x/model');
        $this->traces->closeRun(43, TraceSpan::OK, 100, null);
        $reports = new TraceReports($this->figures, $this->traces, hiddenRun: static fn(int $runId): bool => $runId === 43);

        // spec: BRIEF.md#несколько-пользователей — the private chats of a former owner stay theirs
        self::assertNull($reports->trace(43));
        self::assertNotNull((new TraceReports($this->figures, $this->traces))->trace(43), 'shown without the rule');
    }

    public function testTheTraceSaysWhyTheModelWasChosen(): void {
        // spec: BRIEF.md#выбор-моделей-под-задачу — every decision about the model is shown in /trace
        $this->traces->openRun(44, 'openrouter:x/model');
        $this->traces->closeRun(44, TraceSpan::OK, 100, null);
        $this->figures->decisions[44] = [['method' => 'profile', 'class' => 'profile', 'model' => 'openrouter:x/model', 'reason' => 'needs code, cheapest of three']];

        $this->stack->say('/trace 44');

        self::assertStringContainsString('openrouter:x/model — profile: needs code, cheapest of three', $this->lastText());
    }

    public function testUnknownTraceSaysSo(): void {
        $this->stack->say('/trace 999');

        self::assertSame(\Mercurius\I18n\t(TraceReports::NO_RUN), $this->lastText());
    }

    public function testLongTraceGoesAsAFile(): void {
        $run = $this->traces->openRun(42, 'openrouter:x/model');
        for ($i = 0; $i < 150; $i++) {
            $this->traces->record(new TraceSpan(42, $run, TraceSpan::TOOL, 'read_file', new DateTimeImmutable(), 10, TraceSpan::OK));
        }

        $this->stack->say('/trace 42');

        self::assertCount(1, $this->stack->channel->files);
        self::assertStringContainsString('read_file', $this->stack->channel->files[0][2]);
    }

    public function testReportsAreNotShownInAGroup(): void {
        $this->stack->sayInGroup('/usage');

        self::assertSame(\Mercurius\I18n\t(ChatService::PRIVATE_ONLY), $this->lastText());
        self::assertNull($this->figures->since);
    }

    public function testReportsAreForTheOwnerOnly(): void {
        $this->stack->say('привет', '99');
        $this->stack->press('pv:2:u');
        foreach (['/usage', '/runs', '/trace 42'] as $command) {
            $this->stack->say($command, '99');
            self::assertSame(\Mercurius\I18n\t(ChatService::OWNER_ONLY), $this->lastText(), $command);
        }
    }

    public function testAHolderOfTheReportsPowerSeesReportsButNotTheTracesOfOthersPrivateChats(): void {
        // spec: BRIEF.md#свои-роли-решение-владельца-2026-11-19
        Role::define('analyst', 'Аналитик', 15, [Power::Reports]);
        try {
            $this->stack->principals->put(new Principal(5, PrincipalKind::Human, Role::of('analyst'), 'Аналитик', PrincipalStatus::Active), 'telegram', '55');
            $this->stack->chat->useRunVisibility(static fn(int $runId, Principal $viewer): bool => $runId !== 42);
            $this->traces->openRun(42, 'openrouter:x/model');
            $this->traces->closeRun(42, TraceSpan::OK, 100, null);
            $this->traces->openRun(43, 'openrouter:x/model');
            $this->traces->closeRun(43, TraceSpan::OK, 100, null);

            $this->stack->say('/runs', '55');
            self::assertStringContainsString('#42', $this->lastText());
            $this->stack->say('/trace 42', '55');
            self::assertSame(\Mercurius\I18n\t(TraceReports::NO_RUN), $this->lastText(), 'a private chat of another');
            $this->stack->say('/trace 43', '55');
            self::assertStringContainsString('Запуск #43', $this->lastText());
        } finally {
            Role::reset();
        }
    }
}
