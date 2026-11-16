<?php

declare(strict_types=1);

namespace OpenCCK\Test\Support;

use DateTimeImmutable;
use Mercurius\Messenger\IncomingMessage;
use OpenCCK\App\Agent\AgentLoop;
use OpenCCK\App\Agent\RunScheduler;
use OpenCCK\App\Agent\Tool\ToolExecutor;
use OpenCCK\App\Agent\Tool\ToolRegistry;
use OpenCCK\App\Service\AccessService;
use OpenCCK\App\Service\ChatService;
use OpenCCK\App\Service\PolicyService;
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
    private int $messageId = 0;

    public function __construct(
        public readonly ScriptedLlm $llm = new ScriptedLlm(),
        RunLimits $limits = new RunLimits(30, 100_000, 900, 120),
        float $debounce = 0.0,
    ) {
        $this->runs = new InMemoryRunRepository();
        $this->transcript = new InMemoryTranscriptRepository();
        $this->conversations = new InMemoryConversationRepository();
        $this->principals = new InMemoryPrincipalRepository();
        $this->calls = new InMemoryLlmCallRepository();
        $this->registry = new ToolRegistry();
        $this->channel = new FakeChannel();
        $loop = new AgentLoop($this->llm, $this->runs, $this->transcript, $this->conversations, $this->calls, $this->registry, new ToolExecutor(), 100, null, 10);
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
        $this->chat = new ChatService(new AccessService($this->principals, 'telegram', '42'), $this->conversations, $this->scheduler, $this->channel);
    }

    public function message(string $text, string $userId = '42'): IncomingMessage {
        $id = (string) ++$this->messageId;
        return new IncomingMessage('telegram', '42', $userId, 'owner', 'Owner', $text, $id, null, true, new DateTimeImmutable(), $id);
    }

    public function say(string $text, string $userId = '42'): void {
        $this->chat->handle($this->message($text, $userId));
    }
}
