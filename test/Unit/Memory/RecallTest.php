<?php

declare(strict_types=1);

namespace OpenCCK\Test\Unit\Memory;

use OpenCCK\Memory\App\Recall;
use OpenCCK\Memory\Domain\Fact;
use OpenCCK\Memory\Domain\TextAnalyzer;
use OpenCCK\Test\Support\InMemoryMemoryStore;
use PHPUnit\Framework\TestCase;

final class RecallTest extends TestCase {
    private InMemoryMemoryStore $store;
    private TextAnalyzer $analyzer;
    private Recall $recall;

    protected function setUp(): void {
        $this->store = new InMemoryMemoryStore();
        $this->analyzer = new TextAnalyzer();
        $this->recall = new Recall($this->store, $this->analyzer);
        foreach ([
            'Сервер pve-01 стоит в Хельсинки у Hetzner',
            'Владелец пьёт кофе без сахара',
            'Бэкапы базы делаются каждую ночь в 03:00',
            'Каждый день владелец гуляет с собакой',
            'Каждый день приходят отчёты о расходе',
        ] as $text) {
            $this->add('owner', $text);
        }
    }

    private function add(string $scope, string $text, float $trust = 0.8): Fact {
        return $this->store->addFact($scope, Fact::KNOWLEDGE, $text, $this->analyzer->keys($text), $trust, Fact::OWNER_SAID, null);
    }

    /**
     * @param list<Fact> $facts
     * @return list<string>
     */
    private static function texts(array $facts): array {
        return array_map(static fn(Fact $f): string => $f->text, $facts);
    }

    public function testFindsTheFactTheQuestionIsAbout(): void {
        self::assertSame(['Владелец пьёт кофе без сахара'], self::texts($this->recall->find('Какой кофе я пью?', ['owner'])));
    }

    public function testIdentifierIsDecisive(): void {
        self::assertSame(['Сервер pve-01 стоит в Хельсинки у Hetzner'], self::texts($this->recall->find('что с pve-01', ['owner'])));
    }

    public function testChatterRecallsNothing(): void {
        self::assertSame([], $this->recall->find('ок, спасибо', ['owner']));
        self::assertSame([], $this->recall->find('', ['owner']));
    }

    public function testOneCommonWordOfALongerMessageIsNotEnough(): void {
        self::assertSame([], $this->recall->find('Какая погода будет на этой неделе каждый вечер в Москве?', ['owner']));
    }

    public function testOtherScopesAreNotSeen(): void {
        $this->add('principal:7', 'Гость любит зелёный чай');

        self::assertSame([], $this->recall->find('какой чай любит гость', ['owner']));
        self::assertSame(['Гость любит зелёный чай'], self::texts($this->recall->find('какой чай любит гость', ['principal:7'])));
    }

    public function testBudgetKeepsWholeLines(): void {
        for ($i = 0; $i < 30; $i++) {
            $this->add('chat:t:1', sprintf('Проект альфа: задача номер %d про миграцию базы и длинное описание, чтобы строка была подлиннее обычной', $i));
        }
        $found = $this->recall->find('проект альфа миграция базы', ['chat:t:1']);
        $block = Recall::format($found);

        self::assertNotSame([], $found);
        self::assertLessThanOrEqual(Recall::BUDGET, mb_strlen($block));
        foreach ($found as $fact) {
            self::assertStringContainsString($fact->text, $block);
        }
    }

    public function testFactIsNotRepeatedWithinTheWindow(): void {
        $first = $this->recall->find('Какой кофе я пью?', ['owner'], 42);
        $again = $this->recall->find('кофе с сахаром?', ['owner'], 42);
        $other = $this->recall->find('кофе с сахаром?', ['owner'], 43);

        self::assertCount(1, $first);
        self::assertSame([], $again);
        self::assertCount(1, $other);
    }

    public function testTrustOrdersEqualMatches(): void {
        $low = $this->add('chat:t:2', 'Пароль от роутера лежит в сейфе', 0.3);
        $high = $this->add('chat:t:2', 'Пароль от роутера меняли в мае', 0.9);

        $ids = array_map(static fn(Fact $f): int => $f->id, $this->recall->find('где пароль от роутера', ['chat:t:2']));

        self::assertSame([$high->id, $low->id], $ids);
    }

    public function testFormatMarksIdsAndIsEmptyForNothing(): void {
        self::assertSame('', Recall::format([]));
        $facts = $this->recall->find('pve-01', ['owner']);
        self::assertStringContainsString(sprintf('[#%d] ', $facts[0]->id), Recall::format($facts));
    }
}
