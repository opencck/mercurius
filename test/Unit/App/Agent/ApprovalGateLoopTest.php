<?php

declare(strict_types=1);

namespace OpenCCK\Test\Unit\App\Agent;

use Amp\NullCancellation;
use Amp\PHPUnit\AsyncTestCase;
use Mercurius\Llm\ToolCall;
use OpenCCK\App\Agent\AgentLoop;
use OpenCCK\App\Agent\ApprovalGate;
use OpenCCK\App\Agent\Tool\ToolExecutor;
use OpenCCK\App\Agent\Tool\ToolRegistry;
use OpenCCK\App\Agent\Tool\ValidatedCall;
use OpenCCK\Domain\Agent\ApprovalStatus;
use OpenCCK\Domain\Agent\DangerClass;
use OpenCCK\Domain\Agent\ParticipantPolicy;
use OpenCCK\Domain\Agent\Run;
use OpenCCK\Domain\Agent\RunLimits;
use OpenCCK\Domain\Agent\RunStatus;
use OpenCCK\Domain\Agent\ToolCallStatus;
use OpenCCK\Domain\Entity\Principal;
use OpenCCK\Domain\Entity\PrincipalKind;
use OpenCCK\Domain\Entity\Role;
use OpenCCK\Test\Support\FakeTool;
use OpenCCK\Test\Support\InMemoryApprovalRepository;
use OpenCCK\Test\Support\InMemoryConversationRepository;
use OpenCCK\Test\Support\InMemoryLlmCallRepository;
use OpenCCK\Test\Support\InMemoryRunRepository;
use OpenCCK\Test\Support\InMemoryTranscriptRepository;
use OpenCCK\Test\Support\RecordingObserver;
use OpenCCK\Test\Support\ScriptedLlm;

/**
 * The approval gate inside the loop: irreversible calls pause the run, grants and decisions let them through.
 */
final class ApprovalGateLoopTest extends AsyncTestCase {
    private InMemoryRunRepository $runs;
    private InMemoryTranscriptRepository $transcript;
    private InMemoryApprovalRepository $approvals;
    private RecordingObserver $observer;
    private ToolRegistry $registry;
    private FakeTool $push;
    /** @var list<string> call ids the gate was asked about */
    public array $asked = [];
    /** @var list<array{?RunStatus, ?ToolCallStatus}> run and call status when the gate was asked */
    public array $statesAtRequest = [];

    protected function setUp(): void {
        parent::setUp();
        $this->runs = new InMemoryRunRepository();
        $this->transcript = new InMemoryTranscriptRepository();
        $this->approvals = new InMemoryApprovalRepository();
        $this->observer = new RecordingObserver();
        $this->registry = new ToolRegistry();
        $this->push = new FakeTool('push', DangerClass::Irreversible);
        $this->registry->register($this->push);
        $this->registry->register(new FakeTool('read'));
    }

    private function loop(ScriptedLlm $llm, bool $withGate = true): AgentLoop {
        $conversations = new InMemoryConversationRepository();
        $conversations->openConversation(1, 'test', '1');
        $gate = new class($this, $this->approvals, $this->runs, $this->transcript) implements ApprovalGate {
            public function __construct(
                private readonly ApprovalGateLoopTest $test,
                private readonly InMemoryApprovalRepository $approvals,
                private readonly InMemoryRunRepository $runs,
                private readonly InMemoryTranscriptRepository $transcript,
            ) {}

            public function request(Run $run, ValidatedCall $call, Principal $principal): void {
                $this->test->asked[] = $call->call->id;
                $this->test->statesAtRequest[] = [$this->runs->find($run->id)?->status, $this->transcript->status($run->id, $call->call->id)];
                $this->approvals->create($run->id, $call->call->id, $principal->id, $call->call->name, 'show', null, new \DateTimeImmutable('+30 minutes'));
            }
        };
        return new AgentLoop($llm, $this->runs, $this->transcript, $conversations, new InMemoryLlmCallRepository(), $this->registry, new ToolExecutor(), 100, null, 10, approvals: $this->approvals, gate: $withGate ? $gate : null);
    }

    private function start(): Run {
        $run = $this->runs->start(1, 1, 'test/model', new RunLimits(30, 100_000, 900, 120));
        self::assertNotNull($run);
        $this->transcript->appendUser(1, $run->id, 'задача');
        return $run;
    }

    private function execute(AgentLoop $loop, Run $run, bool $mayAsk = true): Run {
        $owner = new Principal(1, PrincipalKind::Human, Role::Owner, null);
        return $loop->run($run, new ParticipantPolicy(null, $run->limits, null, $mayAsk), $owner, $this->observer, new NullCancellation());
    }

    public function testIrreversibleCallPausesTheRun(): void {
        $llm = new ScriptedLlm([ScriptedLlm::calls(new ToolCall('c1', 'push', '{}'), new ToolCall('c2', 'read', '{}'))]);

        $run = $this->execute($this->loop($llm), $this->start());

        self::assertSame(RunStatus::WaitingApproval, $run->status);
        self::assertSame(['c1'], $this->asked);
        self::assertSame([], $this->push->calls);
        self::assertSame(ToolCallStatus::AwaitingApproval, $this->transcript->status($run->id, 'c1'));
        self::assertSame(ToolCallStatus::Pending, $this->transcript->status($run->id, 'c2'));
        self::assertNotNull($this->observer->waiting);
        self::assertNull($this->observer->final);
    }

    public function testRunIsSavedAsWaitingBeforeTheOwnerIsAsked(): void {
        $llm = new ScriptedLlm([ScriptedLlm::calls(new ToolCall('c1', 'push', '{}'))]);

        $this->execute($this->loop($llm), $this->start());

        self::assertSame([[RunStatus::WaitingApproval, ToolCallStatus::AwaitingApproval]], $this->statesAtRequest);
    }

    public function testApprovedCallRunsWhenTheRunResumes(): void {
        $llm = new ScriptedLlm([ScriptedLlm::calls(new ToolCall('c1', 'push', '{}')), ScriptedLlm::text('отправил')]);
        $loop = $this->loop($llm);
        $run = $this->execute($loop, $this->start());
        $this->approvals->decide(1, ApprovalStatus::Approved, null, 1);
        $this->runs->setStatus($run->id, RunStatus::Running);

        $final = $this->execute($loop, $this->runs->find($run->id) ?? $run);

        self::assertSame(RunStatus::Done, $final->status);
        self::assertCount(1, $this->push->calls);
    }

    public function testDeniedCallGetsTheReason(): void {
        $llm = new ScriptedLlm([ScriptedLlm::calls(new ToolCall('c1', 'push', '{}')), ScriptedLlm::text('понял')]);
        $loop = $this->loop($llm);
        $run = $this->execute($loop, $this->start());
        $this->approvals->decide(1, ApprovalStatus::Denied, 'не сейчас', 1);
        $this->runs->setStatus($run->id, RunStatus::Running);

        $this->execute($loop, $this->runs->find($run->id) ?? $run);

        self::assertSame([], $this->push->calls);
        self::assertStringContainsString('не сейчас', $llm->requests[1]->messages[3]->content);
    }

    public function testGrantedCallRunsWithoutAsking(): void {
        $this->registry = new ToolRegistry();
        $keyed = new \OpenCCK\Test\Support\FakeKeyedTool('push', 'git-push:origin');
        $this->registry->register($keyed);
        $this->approvals->addGrant(1, 'git-push:origin');
        $llm = new ScriptedLlm([ScriptedLlm::calls(new ToolCall('c1', 'push', '{}')), ScriptedLlm::text('готово')]);

        $run = $this->execute($this->loop($llm), $this->start());

        self::assertSame(RunStatus::Done, $run->status);
        self::assertSame([], $this->asked);
        self::assertSame(1, $keyed->runs);
        self::assertStringContainsString('по сохранённому разрешению', $llm->requests[1]->messages[3]->content);
    }

    public function testGrantedCallKnowsItWasAllowed(): void {
        $this->registry = new ToolRegistry();
        $keyed = new \OpenCCK\Test\Support\FakeKeyedTool('push', 'git-push:origin');
        $this->registry->register($keyed);
        $this->approvals->addGrant(1, 'git-push:origin');
        $llm = new ScriptedLlm([ScriptedLlm::calls(new ToolCall('c1', 'push', '{}')), ScriptedLlm::text('готово')]);

        $this->execute($this->loop($llm), $this->start());

        self::assertTrue($keyed->context?->approved);
    }

    public function testApprovedCallKnowsItWasAllowed(): void {
        $this->registry = new ToolRegistry();
        $keyed = new \OpenCCK\Test\Support\FakeKeyedTool('push', null);
        $this->registry->register($keyed);
        $llm = new ScriptedLlm([ScriptedLlm::calls(new ToolCall('c1', 'push', '{}')), ScriptedLlm::text('отправил')]);
        $loop = $this->loop($llm);
        $run = $this->execute($loop, $this->start());
        $this->approvals->decide(1, ApprovalStatus::Approved, null, 1);
        $this->runs->setStatus($run->id, RunStatus::Running);

        $this->execute($loop, $this->runs->find($run->id) ?? $run);

        self::assertTrue($keyed->context?->approved);
    }

    public function testReadCallIsNotMarkedAllowed(): void {
        $seen = null;
        $this->registry = new ToolRegistry();
        $this->registry->register(new FakeTool('read', handler: function (array $args, \OpenCCK\App\Agent\Tool\ToolContext $context) use (&$seen): \OpenCCK\App\Agent\Tool\ToolResult {
            $seen = $context->approved;
            return \OpenCCK\App\Agent\Tool\ToolResult::ok('ok');
        }));
        $llm = new ScriptedLlm([ScriptedLlm::calls(new ToolCall('c1', 'read', '{}')), ScriptedLlm::text('готово')]);

        $this->execute($this->loop($llm), $this->start());

        self::assertFalse($seen);
    }

    public function testOutsideDataSuspendsSavedGrants(): void {
        $this->registry = new ToolRegistry();
        $keyed = new \OpenCCK\Test\Support\FakeKeyedTool('push', 'git-push:origin');
        $this->registry->register($keyed);
        $this->registry->register(new \OpenCCK\Test\Support\FakeOutsideTool('web'));
        $this->approvals->addGrant(1, 'git-push:origin');
        $llm = new ScriptedLlm([ScriptedLlm::calls(new ToolCall('c1', 'web', '{}')), ScriptedLlm::calls(new ToolCall('c2', 'push', '{}'))]);

        $run = $this->execute($this->loop($llm), $this->start());

        self::assertSame(RunStatus::WaitingApproval, $run->status);
        self::assertSame(['c2'], $this->asked);
        self::assertSame(0, $keyed->runs);
        self::assertSame('web', $this->runs->find($run->id)?->outside);
    }

    public function testRunStartedWithOutsideDataAsksDespiteAGrant(): void {
        $this->registry = new ToolRegistry();
        $keyed = new \OpenCCK\Test\Support\FakeKeyedTool('push', 'git-push:origin');
        $this->registry->register($keyed);
        $this->approvals->addGrant(1, 'git-push:origin');
        $llm = new ScriptedLlm([ScriptedLlm::calls(new ToolCall('c1', 'push', '{}'))]);
        $run = $this->runs->start(1, 1, 'test/model', new RunLimits(30, 100_000, 900, 120), null, 'group chat');
        self::assertNotNull($run);
        $this->transcript->appendUser(1, $run->id, 'задача');

        $this->execute($this->loop($llm), $run);

        self::assertSame(['c1'], $this->asked);
        self::assertSame(0, $keyed->runs);
    }

    public function testTheNextRunSeesWhatThisOneDid(): void {
        $this->registry = new ToolRegistry();
        $this->registry->register(new FakeTool('save', DangerClass::Write, handler: static fn(): \OpenCCK\App\Agent\Tool\ToolResult => \OpenCCK\App\Agent\Tool\ToolResult::ok('Отправлено в личный чат: Roman.')));
        $this->registry->register(new FakeTool('look'));
        $llm = new ScriptedLlm([ScriptedLlm::calls(new ToolCall('c1', 'look', '{}'), new ToolCall('c2', 'save', '{"to":"Roman"}')), ScriptedLlm::text('Спросил.')]);

        $run = $this->execute($this->loop($llm), $this->start());

        $history = $this->transcript->historyEntries(1, $run->id + 1, 10);
        $answer = array_pop($history);
        $noted = array_pop($history);
        self::assertNotNull($answer);
        self::assertNotNull($noted);
        self::assertSame('Спросил.', $answer->message->content, 'the answer stays as written');
        $note = $noted->message->content;
        self::assertStringContainsString('save {"to":"Roman"} → Отправлено в личный чат: Roman.', $note, 'tool rounds are not in the history of earlier runs: what was done is kept next to the answer');
        self::assertStringNotContainsString('look', $note, 'reads are not actions');
    }

    public function testNoOneToAskDenies(): void {
        $llm = new ScriptedLlm([ScriptedLlm::calls(new ToolCall('c1', 'push', '{}')), ScriptedLlm::text('не смог')]);

        $run = $this->execute($this->loop($llm), $this->start(), mayAsk: false);

        self::assertSame(RunStatus::Done, $run->status);
        self::assertSame([], $this->push->calls);
        self::assertStringContainsString('требует подтверждения владельца', $llm->requests[1]->messages[3]->content);
    }
}
