<?php

declare(strict_types=1);

namespace OpenCCK\Test\Unit\App\Agent;

use Amp\PHPUnit\AsyncTestCase;
use OpenCCK\App\Agent\FactNudge;

/**
 * The background collector of facts: what it takes from the cheap model's answer.
 *
 * spec: BRIEF.md#память-слои — the background proposal of facts
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

    public function testThePromptSaysEnvironmentFailuresAreNotFacts(): void {
        // spec: BRIEF.md#память-слои — a failure of the environment is not a fact
        self::assertStringContainsString('таймауты', \Mercurius\I18n\t('agent.fact_nudge.prompt'));
    }
}
