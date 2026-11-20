<?php

declare(strict_types=1);

namespace OpenCCK\Test\Unit\App\Agent;

use Amp\PHPUnit\AsyncTestCase;
use Mercurius\Memory\App\MemoryContext;
use Mercurius\Memory\App\Recall;
use Mercurius\Memory\Domain\Fact;
use Mercurius\Memory\Domain\TextAnalyzer;
use OpenCCK\Test\Support\AgentStack;
use Mercurius\Memory\Infrastructure\InMemoryMemoryStore;
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

    public function testTheMemoryFileGoesWholeIntoThePromptOfAPrivateChat(): void {
        // spec: BRIEF.md#всегда-в-контексте — MEMORY.md of the participant, whole, with a limit
        $this->stack->scheduler->useMemoryFile(static fn(\OpenCCK\Domain\Entity\Principal $p): string => "Дочь — Аня\nПароль от Wi-Fi в заметках", 1000);

        $this->stack->say('привет');
        $this->stack->scheduler->awaitIdle();

        $system = $this->stack->llm->requests[0]->messages[0]->content;
        self::assertStringContainsString('Дочь — Аня', $system);
        self::assertStringNotContainsString(\Mercurius\I18n\t('memory.file.over'), $system);
    }

    public function testAMemoryFileOverItsLimitIsCutAndTheAgentToldToCompressIt(): void {
        $this->stack->scheduler->useMemoryFile(static fn(\OpenCCK\Domain\Entity\Principal $p): string => str_repeat('запись ', 400), 600);

        $this->stack->say('привет');
        $this->stack->scheduler->awaitIdle();

        $system = $this->stack->llm->requests[0]->messages[0]->content;
        self::assertStringContainsString(sprintf(\Mercurius\I18n\t('memory.file.over'), 2799, 600), $system, 'the file is trimmed: the last space goes');
        self::assertLessThan(2800, mb_substr_count($system, 'запись') * 7, 'only the head of the file');
    }

    public function testAGroupDoesNotGetTheMemoryFile(): void {
        $this->stack->scheduler->useMemoryFile(static fn(\OpenCCK\Domain\Entity\Principal $p): string => 'Дочь — Аня', 1000);

        $this->stack->sayInGroup('привет');
        $this->stack->scheduler->awaitIdle();

        self::assertStringNotContainsString('Дочь — Аня', $this->stack->llm->requests[0]->messages[0]->content);
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
