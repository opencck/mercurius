<?php

declare(strict_types=1);

namespace OpenCCK\Test\Unit\App\Service;

use Amp\PHPUnit\AsyncTestCase;
use OpenCCK\App\Service\AccessService;
use OpenCCK\App\Service\ChatService;
use OpenCCK\App\Service\MemoryCommands;
use Mercurius\Memory\App\MemoryContext;
use Mercurius\Memory\App\Proposals;
use Mercurius\Memory\App\Recall;
use Mercurius\Memory\Domain\ContentScreen;
use Mercurius\Memory\Domain\Fact;
use Mercurius\Memory\Domain\Proposal;
use Mercurius\Memory\Domain\TextAnalyzer;
use OpenCCK\Test\Support\AgentStack;
use Mercurius\Memory\Infrastructure\InMemoryMemoryStore;

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
        $aliases = new \Mercurius\Memory\App\Aliases(new \Mercurius\Memory\Infrastructure\InMemoryAliasStore(), $analyzer, $this->store, new ContentScreen());
        $commands = new MemoryCommands($this->store, $this->proposals, new MemoryContext($this->store, new Recall($this->store, $analyzer)), null, $aliases);
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
        // a new fact is written at once: what waits is a change
        $this->proposals->propose(Proposal::CHANGE, $fact->id, 'Кофе без сахара', 'сказал', ['owner'], null, null);

        $this->chat->handle($this->stack->message('/memory'));

        $reply = $this->lastReply();
        self::assertStringContainsString(sprintf('#%d Сервер pve-01 в Хельсинки', $fact->id), $reply);
        self::assertStringContainsString('Любит короткие ответы', $reply);
        self::assertStringContainsString('№1 change: Кофе без сахара', $reply);
        self::assertStringNotContainsString('Чужой факт', $reply);
    }

    public function testFactsGoByPagesAndByWords(): void {
        // spec: brief/memory.md#как-сделано-области-и-факты — /memory <words> and pages; every fact shows where it came from
        for ($i = 1; $i <= 25; $i++) {
            $this->store->addFact('owner', Fact::KNOWLEDGE, 'Заметка номер ' . $i, ['заметк', 'н' . $i], 0.6, Fact::AGENT, null);
        }
        $coffee = $this->store->addFact('owner', Fact::KNOWLEDGE, 'Пьёт кофе без сахара', ['пьёт', 'коф', 'сахар'], 0.7, Fact::OWNER_SAID, null);

        $this->chat->handle($this->stack->message('/memory 2'));
        $second = $this->lastReply();
        $this->chat->handle($this->stack->message('/memory кофе'));
        $found = $this->lastReply();

        self::assertStringContainsString(sprintf(\Mercurius\I18n\t('service.memory_commands.page'), 2, 2), $second);
        self::assertStringContainsString('Заметка номер 1', $second, 'the oldest are on the last page');
        self::assertStringNotContainsString('Пьёт кофе', $second);
        self::assertStringContainsString(sprintf('#%d Пьёт кофе без сахара', $coffee->id), $found);
        self::assertStringContainsString(\Mercurius\I18n\t('memory.provenance.owner_said'), $found);
        self::assertStringNotContainsString('Заметка номер', $found);
    }

    public function testForgetRemovesTheFact(): void {
        $fact = $this->store->addFact('owner', Fact::KNOWLEDGE, 'Секретный план', ['план'], 0.8, Fact::OWNER_SAID, null);

        $this->chat->handle($this->stack->message('/forget ' . $fact->id));

        self::assertStringContainsString('забыт', $this->lastReply());
        self::assertNull($this->store->fact($fact->id));
        self::assertNotSame([], $this->store->tombstones);
    }

    public function testTheOwnerEditsTheTableOfWords(): void {
        // spec: brief/memory.md#словари — the owner sees and edits the table
        $this->chat->handle($this->stack->message('/aliases + прокс, Proxmox'));
        self::assertStringContainsString('#1: прокс, proxmox', $this->lastReply());

        $this->chat->handle($this->stack->message('/aliases'));
        self::assertStringContainsString('#1: прокс, proxmox', $this->lastReply());

        $this->chat->handle($this->stack->message('/aliases - 1'));
        $this->chat->handle($this->stack->message('/aliases'));
        self::assertStringContainsString(\Mercurius\I18n\t('app.aliases.none'), $this->lastReply());
    }

    public function testDreamWithoutAModelOfSleepSaysSoAndStillGuards(): void {
        // spec: brief/memory.md#страж-потерь — /dream runs the guard; without a model of sleep there is no sleep
        $analyzer = new TextAnalyzer();
        $commands = new MemoryCommands($this->store, $this->proposals, new MemoryContext($this->store, new Recall($this->store, $analyzer)), null, null,
            new \Mercurius\Memory\App\LossGuard($this->store, $journal = new \Mercurius\Memory\Infrastructure\InMemoryGuardJournal(), static fn(string $t): bool => true),
            static fn(): bool => false);

        self::assertSame(\Mercurius\I18n\t('service.memory_commands.sleep_off'), $commands->consolidate());
        self::assertCount(1, $journal->checks, 'the guard took its snapshot');
    }

    public function testOnlyTheOwnerAndOnlyInPrivate(): void {
        $this->chat->handle($this->stack->message('/memory', '42', false));
        self::assertSame(\Mercurius\I18n\t(ChatService::MEMORY_PRIVATE), $this->lastReply());
    }
}
