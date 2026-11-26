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

    /**
     * spec: brief/memory.md#как-сделано-области-и-факты — facts are looked up by the participant's own words only: the
     * answer of someone else (relay) and what the files of a message said bring none
     */
    public function testFactsAreLookedUpByTheOwnWordsOnly(): void {
        $owner = $this->stack->principals->ensureOwner('telegram', '42', 'Owner');
        $conversation = $this->stack->conversations->openConversation($owner->id, 'telegram', '42');
        $message = static fn(string $text): \Mercurius\Messenger\IncomingMessage => new \Mercurius\Messenger\IncomingMessage('telegram', '42', '42', 'owner', 'Owner', $text, '9', null, true, new \DateTimeImmutable(), '9');

        $this->stack->scheduler->submit($owner, $message('Ответ Ани: какой кофе пьёт владелец — без сахара'), $conversation, false, 'relay');
        $this->stack->scheduler->awaitIdle();
        self::assertStringNotContainsString('untrusted-data source="memory"', $this->userMessage(), 'someone else\'s words bring no facts');

        $this->stack->scheduler->submit($owner, $message("глянь\n\n📎 фото: чашка кофе без сахара"), $conversation, false, 'attachments', 'глянь');
        $this->stack->scheduler->awaitIdle();
        $last = array_slice($this->stack->llm->requests[1]->messages, -1)[0] ?? null;
        self::assertNotNull($last);
        self::assertStringNotContainsString('untrusted-data source="memory"', $last->content, 'what a file said brings no facts');
    }

    public function testTheMemoryFileGoesWholeIntoThePromptOfAPrivateChat(): void {
        // spec: brief/memory.md#правила-и-заметки-memorymd — a file without headings is notes, reference data
        $this->stack->scheduler->useMemoryFile(static fn(\OpenCCK\Domain\Entity\Member $p): string => "Дочь — Аня\nПароль от Wi-Fi в заметках", 1000);

        $this->stack->say('привет');
        $this->stack->scheduler->awaitIdle();

        $system = $this->stack->llm->requests[0]->messages[0]->content;
        self::assertStringContainsString('Дочь — Аня', $system);
        self::assertStringNotContainsString(\Mercurius\I18n\t('memory.file.over'), $system);
    }

    public function testAMemoryFileOverItsLimitIsCutAndTheAgentToldToCompressIt(): void {
        $this->stack->scheduler->useMemoryFile(static fn(\OpenCCK\Domain\Entity\Member $p): string => str_repeat('запись ', 400), 600);

        $this->stack->say('привет');
        $this->stack->scheduler->awaitIdle();

        $system = $this->stack->llm->requests[0]->messages[0]->content;
        self::assertStringContainsString(sprintf(\Mercurius\I18n\t('memory.file.over'), 2799, 600), $system, 'the file is trimmed: the last space goes');
        self::assertLessThan(2800, mb_substr_count($system, 'запись') * 7, 'only the head of the file');
    }

    public function testTheRulesAreInstructionsAndTheNotesReferenceData(): void {
        // spec: brief/memory.md#правила-и-заметки-memorymd — two sections, two blocks of the prompt
        $this->stack->scheduler->useMemoryFile(static fn(\OpenCCK\Domain\Entity\Member $p): string => "## Правила\n- Отвечай коротко\n\n## Заметки\n- Дочь — Аня", 1000);

        $this->stack->say('привет');
        $this->stack->scheduler->awaitIdle();

        $system = $this->stack->llm->requests[0]->messages[0]->content;
        $rules = mb_strpos($system, \Mercurius\I18n\t('memory.file.rules_person'));
        self::assertNotFalse($rules);
        self::assertStringContainsString("- Отвечай коротко", $system);
        $wrapped = mb_strpos($system, 'untrusted-data source="memory"');
        self::assertNotFalse($wrapped);
        self::assertLessThan($wrapped, $rules, 'the rules stand before and outside the reference data');
        self::assertGreaterThan($wrapped, mb_strpos($system, 'Дочь — Аня'), 'the notes are reference data');
        self::assertStringContainsString(sprintf(\Mercurius\I18n\t('memory.file.fill'), 3, 29, 1000), $system);
    }

    public function testOverTheLimitTheNotesAreCutNeverARule(): void {
        $this->stack->scheduler->useMemoryFile(static fn(\OpenCCK\Domain\Entity\Member $p): string => "## Rules\n- Всегда отвечай по-русски\n## Notes\n" . str_repeat('заметка ', 200), 600);

        $this->stack->say('привет');
        $this->stack->scheduler->awaitIdle();

        $system = $this->stack->llm->requests[0]->messages[0]->content;
        self::assertStringContainsString('- Всегда отвечай по-русски', $system);
        self::assertStringContainsString(sprintf(\Mercurius\I18n\t('memory.file.over'), 1625, 600), $system);
    }

    public function testCloseToTheLimitTheAgentIsAskedToCompressBeforeAnythingIsCut(): void {
        $this->stack->scheduler->useMemoryFile(static fn(\OpenCCK\Domain\Entity\Member $p): string => str_repeat('я', 900), 1000);

        $this->stack->say('привет');
        $this->stack->scheduler->awaitIdle();

        $system = $this->stack->llm->requests[0]->messages[0]->content;
        self::assertStringContainsString(\Mercurius\I18n\t('memory.file.near'), $system);
        self::assertStringContainsString(sprintf(\Mercurius\I18n\t('memory.file.fill'), 90, 900, 1000), $system);
    }

    public function testNoFileAnEmptyOneAndAnEmptySectionGiveNothing(): void {
        foreach (['', "  \n ", "## Правила\n\n## Заметки\n"] as $i => $text) {
            $stack = new AgentStack(new ScriptedLlm([ScriptedLlm::text('ок')]));
            $stack->scheduler->useMemoryFile(static fn(\OpenCCK\Domain\Entity\Member $p): string => $text, 1000);
            $stack->say('привет');
            $stack->scheduler->awaitIdle();
            $system = $stack->llm->requests[0]->messages[0]->content;
            self::assertStringNotContainsString(\Mercurius\I18n\t('memory.file.head'), $system, 'case ' . $i);
            self::assertStringNotContainsString(\Mercurius\I18n\t('memory.file.rules_person'), $system, 'case ' . $i);
        }
    }

    public function testAGroupGetsTheFileOfThePlaceNotThePersonsOwn(): void {
        // spec: brief/memory.md#файл-памяти-места — the place's rules for everyone who writes there
        $this->stack->scheduler->useMemoryFile(static fn(\OpenCCK\Domain\Entity\Member $p): string => 'Дочь — Аня', 1000);
        $this->stack->scheduler->usePlaceMemory(static fn(\OpenCCK\Domain\Entity\Member $p, string $channel, string $chatId, string $threadId, bool $private): string => $private ? '' : "## Правила\nВ этой группе — только по делу\n## Заметки\nДежурный — Паша");

        $this->stack->sayInGroup('привет');
        $this->stack->scheduler->awaitIdle();

        $system = $this->stack->llm->requests[0]->messages[0]->content;
        self::assertStringContainsString(\Mercurius\I18n\t('memory.file.rules_place'), $system);
        self::assertStringContainsString('В этой группе — только по делу', $system);
        self::assertStringContainsString('Дежурный — Паша', $system);
        self::assertStringNotContainsString('Дочь — Аня', $system, 'the person\'s own file stays in their private chat');
    }

    public function testAPrivateChatOfAProjectGetsBothFiles(): void {
        $this->stack->scheduler->useMemoryFile(static fn(\OpenCCK\Domain\Entity\Member $p): string => "## Правила\nОтвечай коротко", 1000);
        $this->stack->scheduler->usePlaceMemory(static fn(\OpenCCK\Domain\Entity\Member $p, string $channel, string $chatId, string $threadId, bool $private): string => "## Правила\nВ проекте — по-английски");

        $this->stack->say('привет');
        $this->stack->scheduler->awaitIdle();

        $system = $this->stack->llm->requests[0]->messages[0]->content;
        self::assertStringContainsString('Отвечай коротко', $system);
        self::assertStringContainsString('В проекте — по-английски', $system);
    }

    public function testAGroupDoesNotGetTheMemoryFile(): void {
        $this->stack->scheduler->useMemoryFile(static fn(\OpenCCK\Domain\Entity\Member $p): string => 'Дочь — Аня', 1000);

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
