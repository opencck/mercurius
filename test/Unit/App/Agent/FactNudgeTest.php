<?php

declare(strict_types=1);

namespace OpenCCK\Test\Unit\App\Agent;

use Amp\PHPUnit\AsyncTestCase;
use OpenCCK\App\Agent\FactNudge;

/**
 * The background collector of facts: what it takes from the cheap model's answer.
 *
 * spec: brief/memory.md#факты — the background proposal of facts
 */
final class FactNudgeTest extends AsyncTestCase {
    public function testTheAnswerGivesAtMostThreeFactsWithTheirReasons(): void {
        $answer = "Вот:\n```json\n" . json_encode(['facts' => [
            ['text' => 'Сервер pve-01 в Хельсинки', 'reason' => 'сказал сам'],
            ['text' => '  ', 'reason' => 'пусто'],
            ['text' => 'Пьёт кофе без сахара'],
            ['text' => 'Работает из Бангкока', 'reason' => str_repeat('я', 400)],
            ['text' => 'Четвёртый факт', 'reason' => 'лишний'],
        ]], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n```";

        $facts = FactNudge::facts($answer);

        self::assertSame(['Сервер pve-01 в Хельсинки', 'Пьёт кофе без сахара', 'Работает из Бангкока'], array_column($facts, 'text'));
        self::assertSame('', $facts[1]['reason']);
        self::assertSame(300, mb_strlen($facts[2]['reason']), 'a reason is cut');
    }

    public function testAnAnswerThatIsNotTheJsonAskedGivesNothing(): void {
        self::assertSame([], FactNudge::facts('Фактов нет.'));
        self::assertSame([], FactNudge::facts('{"facts": "не список"}'));
        self::assertSame([], FactNudge::facts('{broken'));
    }

    public function testStandingInstructionsAreRulesNotFacts(): void {
        // spec: brief/memory.md#одно-знание--в-одно-место — a rule goes to the «Правила» of MEMORY.md, not into a fact
        $answer = json_encode(['facts' => [], 'rules' => ['Всегда отвечай коротко', 42, '', 'Код давай целиком', 'Третье правило']], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        self::assertSame(['Всегда отвечай коротко', 'Код давай целиком'], FactNudge::rules($answer), 'at most two, strings only');
        self::assertSame([], FactNudge::rules('{"facts": []}'));
        self::assertStringContainsString('"rules"', \Mercurius\I18n\t('agent.fact_nudge.prompt'));
    }

    public function testARuleIsAddedToTheRulesOfTheFileOnce(): void {
        $file = \OpenCCK\App\Agent\MemoryFile::parse("Дочь — Аня");

        $next = $file->withRule('Всегда отвечай коротко');

        self::assertNotNull($next);
        self::assertSame("## Правила\n- Всегда отвечай коротко\n\n## Заметки\nДочь — Аня\n", $next->text());
        self::assertNull($next->withRule('- всегда отвечай коротко'), 'the same rule is there already');
        self::assertSame('- Всегда отвечай коротко', \OpenCCK\App\Agent\MemoryFile::parse($next->text())->rules, 'written back, read the same');
    }

    public function testThePromptSaysEnvironmentFailuresAreNotFacts(): void {
        // spec: brief/memory.md#факты — a failure of the environment is not a fact
        self::assertStringContainsString('таймауты', \Mercurius\I18n\t('agent.fact_nudge.prompt'));
    }

    /**
     * spec: brief/memory.md#факты — the collector reads what was said, not what memory put into the message
     * ref: references/hermes-plugin-holographic/__init__.py@9b49943:L378-L381 — the blocks the
     *      runtime adds to a turn are no words of the person
     */
    public function testTheMemoryBlockOfAMessageIsNotReadAsSaid(): void {
        $said = "Какой кофе я пью?\n\n" . \OpenCCK\App\Agent\Tool\Guard\UntrustedWrapper::wrap('memory', "Из памяти:\n[#3] Владелец пьёт кофе без сахара");

        self::assertSame('Какой кофе я пью?', FactNudge::said($said));
        self::assertSame('просто текст', FactNudge::said('просто текст'));
        $kept = "глянь\n\n" . \OpenCCK\App\Agent\Tool\Guard\UntrustedWrapper::wrap('attachments', 'фото: чашка');
        self::assertSame($kept, FactNudge::said($kept), 'what a file said stays: only the memory block is taken out');
    }
}
