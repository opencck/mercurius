<?php

declare(strict_types=1);

namespace OpenCCK\Test\Unit\App\Agent;

use Amp\PHPUnit\AsyncTestCase;
use OpenCCK\Memory\App\MemoryContext;
use OpenCCK\Memory\App\Recall;
use OpenCCK\Memory\Domain\Fact;
use OpenCCK\Memory\Domain\TextAnalyzer;
use OpenCCK\Test\Support\AgentStack;
use OpenCCK\Test\Support\InMemoryMemoryStore;
use OpenCCK\Test\Support\ScriptedLlm;

final class RunSchedulerMemoryTest extends AsyncTestCase {
    private AgentStack $stack;
    private InMemoryMemoryStore $store;

    protected function setUp(): void {
        parent::setUp();
        $this->stack = new AgentStack(new ScriptedLlm([ScriptedLlm::text('ок'), ScriptedLlm::text('ок')]));
        $this->store = new InMemoryMemoryStore();
        $analyzer = new TextAnalyzer();
        $text = 'Владелец пьёт кофе без сахара';
        $this->store->addFact('owner', Fact::KNOWLEDGE, $text, $analyzer->keys($text), 0.9, Fact::OWNER_SAID, null);
        $this->store->setCore('profile', 'Владелец любит короткие ответы');
        $this->stack->scheduler->useMemory(new MemoryContext($this->store, new Recall($this->store, $analyzer)));
    }

    private function userMessage(): string {
        $last = array_slice($this->stack->llm->requests[0]->messages, -1)[0] ?? null;
        self::assertNotNull($last);
        return $last->content;
    }

    public function testOwnerInPrivateGetsFactsAndTheCore(): void {
        $this->stack->say('Какой кофе я пью?');
        $this->stack->scheduler->awaitIdle();

        $user = $this->userMessage();
        self::assertStringStartsWith('Какой кофе я пью?', $user);
        self::assertStringContainsString('untrusted-data source="memory"', $user);
        self::assertStringContainsString('Владелец пьёт кофе без сахара', $user);
        self::assertStringContainsString('Владелец любит короткие ответы', $this->stack->llm->requests[0]->messages[0]->content);
    }

    public function testGroupGetsNeitherOwnerFactsNorTheCore(): void {
        $this->stack->sayInGroup('Какой кофе я пью?');
        $this->stack->scheduler->awaitIdle();

        self::assertSame('Какой кофе я пью?', $this->userMessage());
        self::assertStringNotContainsString('короткие ответы', $this->stack->llm->requests[0]->messages[0]->content);
    }

    public function testNothingRelevantLeavesTheMessageAsIs(): void {
        $this->stack->say('привет');
        $this->stack->scheduler->awaitIdle();

        self::assertSame('привет', $this->userMessage());
    }
}
