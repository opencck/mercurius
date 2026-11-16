<?php

declare(strict_types=1);

namespace OpenCCK\Test\Unit\App\Service;

use Amp\PHPUnit\AsyncTestCase;
use OpenCCK\App\Service\AccessService;
use OpenCCK\App\Service\ChatService;
use OpenCCK\App\Service\MemoryCommands;
use OpenCCK\Memory\App\MemoryContext;
use OpenCCK\Memory\App\Proposals;
use OpenCCK\Memory\App\Recall;
use OpenCCK\Memory\Domain\ContentScreen;
use OpenCCK\Memory\Domain\Fact;
use OpenCCK\Memory\Domain\Proposal;
use OpenCCK\Memory\Domain\TextAnalyzer;
use OpenCCK\Test\Support\AgentStack;
use OpenCCK\Test\Support\InMemoryMemoryStore;

final class MemoryCommandsTest extends AsyncTestCase {
    private AgentStack $stack;
    private InMemoryMemoryStore $store;
    private Proposals $proposals;
    private ChatService $chat;

    protected function setUp(): void {
        parent::setUp();
        $this->stack = new AgentStack();
        $this->store = new InMemoryMemoryStore();
        $analyzer = new TextAnalyzer();
        $this->proposals = new Proposals($this->store, $analyzer, new ContentScreen(), 'k');
        $commands = new MemoryCommands($this->store, $this->proposals, new MemoryContext($this->store, new Recall($this->store, $analyzer)));
        $this->chat = new ChatService(new AccessService($this->stack->principals, 'telegram', '42', $this->stack->visitors), $this->stack->conversations, $this->stack->scheduler, $this->stack->channel, memory: $commands);
    }

    private function lastReply(): string {
        $sent = $this->stack->channel->texts('send');
        return (string) end($sent);
    }

    public function testOverviewShowsFactsCoreAndPending(): void {
        $fact = $this->store->addFact('owner', Fact::KNOWLEDGE, 'Сервер pve-01 в Хельсинки', ['pve-01'], 0.8, Fact::OWNER_SAID, null);
        $this->store->addFact('principal:9', Fact::KNOWLEDGE, 'Чужой факт', ['чуж'], 0.8, Fact::OWNER_SAID, null);
        $this->store->setCore('profile', 'Любит короткие ответы');
        $this->proposals->propose(Proposal::ADD, null, 'Кофе без сахара', 'сказал', ['owner'], null, null);

        $this->chat->handle($this->stack->message('/memory'));

        $reply = $this->lastReply();
        self::assertStringContainsString(sprintf('#%d Сервер pve-01 в Хельсинки', $fact->id), $reply);
        self::assertStringContainsString('Любит короткие ответы', $reply);
        self::assertStringContainsString('№1 add: Кофе без сахара', $reply);
        self::assertStringNotContainsString('Чужой факт', $reply);
    }

    public function testForgetRemovesTheFact(): void {
        $fact = $this->store->addFact('owner', Fact::KNOWLEDGE, 'Секретный план', ['план'], 0.8, Fact::OWNER_SAID, null);

        $this->chat->handle($this->stack->message('/forget ' . $fact->id));

        self::assertStringContainsString('забыт', $this->lastReply());
        self::assertNull($this->store->fact($fact->id));
        self::assertNotSame([], $this->store->tombstones);
    }

    public function testOnlyTheOwnerAndOnlyInPrivate(): void {
        $this->chat->handle($this->stack->message('/memory', '42', false));
        self::assertSame(ChatService::MEMORY_PRIVATE, $this->lastReply());
    }
}
