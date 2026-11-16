<?php

declare(strict_types=1);

namespace OpenCCK\Test\Support;

use DateTimeImmutable;
use Mercurius\Messenger\IncomingMessage;
use Mercurius\Messenger\IncomingCallback;
use OpenCCK\App\Agent\AgentLoop;
use OpenCCK\App\Agent\ApprovalService;
use OpenCCK\App\Agent\CallbackRouter;
use OpenCCK\App\Agent\RunScheduler;
use OpenCCK\App\Agent\Tool\ToolExecutor;
use OpenCCK\App\Agent\Tool\ToolRegistry;
use OpenCCK\App\Service\AccessService;
use OpenCCK\App\Service\ChatService;
use OpenCCK\App\Service\PolicyService;
use OpenCCK\App\Service\VisitorService;
use OpenCCK\Domain\Agent\RunLimits;

/**
 * The whole agent on in-memory stores, a scripted model and a fake channel. Owner is Telegram user 42.
 */
final class AgentStack {
    public readonly InMemoryRunRepository $runs;
    public readonly InMemoryTranscriptRepository $transcript;
    public readonly InMemoryConversationRepository $conversations;
    public readonly InMemoryPrincipalRepository $principals;
    public readonly InMemoryLlmCallRepository $calls;
    public readonly ToolRegistry $registry;
    public readonly FakeChannel $channel;
    public readonly RunScheduler $scheduler;
    public readonly ChatService $chat;
    public readonly InMemoryApprovalRepository $approvals;
    public readonly ApprovalService $approvalService;
    public readonly CallbackRouter $callbacks;
    public readonly VisitorService $visitors;
    private int $messageId = 0;

    public function __construct(
        public readonly ScriptedLlm $llm = new ScriptedLlm(),
        RunLimits $limits = new RunLimits(30, 100_000, 900, 120),
        float $debounce = 0.0,
        int $maxMessageLength = 4096,
    ) {
        $this->runs = new InMemoryRunRepository();
        $this->transcript = new InMemoryTranscriptRepository();
        $this->conversations = new InMemoryConversationRepository();
        $this->principals = new InMemoryPrincipalRepository();
        $this->calls = new InMemoryLlmCallRepository();
        $this->registry = new ToolRegistry();
        $this->channel = new FakeChannel(max(1, $maxMessageLength));
        $this->approvals = new InMemoryApprovalRepository(fn(int $runId): int => $this->runs->find($runId)->conversationId ?? 0);
        $this->approvalService = new ApprovalService($this->approvals, $this->principals, $this->channel, '42', 1800);
        $loop = new AgentLoop($this->llm, $this->runs, $this->transcript, $this->conversations, $this->calls, $this->registry, new ToolExecutor(), 100, null, 10, approvals: $this->approvals, gate: $this->approvalService);
        $this->scheduler = new RunScheduler(
            $loop,
            $this->runs,
            $this->transcript,
            $this->conversations,
            $this->principals,
            new PolicyService(new InMemoryPolicyRepository(), $limits),
            $this->channel,
            'test/model',
            debounce: $debounce,
            editInterval: 0.0,
        );
        $this->scheduler->useApprovals($this->approvalService);
        $this->approvalService->onDecision($this->scheduler->resumeRun(...));
        $this->visitors = new VisitorService($this->principals, $this->channel, '42');
        $this->callbacks = new CallbackRouter($this->channel, '42', ['ap' => $this->approvalService, 'pg' => $this->approvalService, 'pv' => $this->visitors]);
        $this->chat = new ChatService(new AccessService($this->principals, 'telegram', '42', $this->visitors), $this->conversations, $this->scheduler, $this->channel, $this->approvalService);
    }

    /** A press on a button, as the channel delivers it. */
    public function press(string $data, string $userId = '42', string $messageId = '1'): void {
        $this->callbacks->handleCallback(new IncomingCallback('telegram', 'cb' . ++$this->messageId, '42', $userId, 'Owner', $messageId, $data, (string) $this->messageId));
    }

    public function message(string $text, string $userId = '42'): IncomingMessage {
        $id = (string) ++$this->messageId;
        return new IncomingMessage('telegram', '42', $userId, 'owner', 'Owner', $text, $id, null, true, new DateTimeImmutable(), $id);
    }

    public function say(string $text, string $userId = '42'): void {
        $this->chat->handle($this->message($text, $userId));
    }
}
