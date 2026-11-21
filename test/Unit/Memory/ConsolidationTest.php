<?php

declare(strict_types=1);

namespace OpenCCK\Test\Unit\Memory;

use Amp\PHPUnit\AsyncTestCase;
use OpenCCK\Infrastructure\Module\MemoryConsolidationModule;
use Mercurius\Memory\App\Consolidation;
use Mercurius\Memory\App\Proposals;
use Mercurius\Memory\Domain\ConsolidationJournal;
use Mercurius\Memory\Domain\ContentScreen;
use Mercurius\Memory\Domain\Fact;
use Mercurius\Memory\Domain\Proposal;
use Mercurius\Memory\Domain\TextAnalyzer;
use Mercurius\Memory\Infrastructure\InMemoryConsolidationJournal;
use Mercurius\Memory\Infrastructure\InMemoryMemoryStore;

final class ConsolidationTest extends AsyncTestCase {
    private InMemoryMemoryStore $store;
    private InMemoryConsolidationJournal $journal;
    private TextAnalyzer $analyzer;
    private Consolidation $consolidation;
    /** @var list<Proposal> */
    private array $shown = [];
    /** @var list<string> */
    private array $told = [];
    private int $now = 1_800_000_000;
    private bool $delivered = true;

    protected function setUp(): void {
        parent::setUp();
        $this->store = new InMemoryMemoryStore();
        $this->journal = new InMemoryConsolidationJournal();
        $this->journal->now = fn(): int => $this->now;
        $this->analyzer = new TextAnalyzer();
        $this->consolidation = new Consolidation(
            $this->store,
            new Proposals($this->store, $this->analyzer, new ContentScreen(), 'k'),
            $this->journal,
            function (Proposal $p): bool {
                $this->shown[] = $p;
                return true;
            },
            function (string $text): bool {
                $this->told[] = $text;
                return $this->delivered;
            },
            fn(): int => $this->now,
        );
    }

    private function add(string $text, float $trust = 0.8, string $scope = 'owner', int $age = 0, string $provenance = Fact::OWNER_SAID): Fact {
        $fact = $this->store->addFact($scope, Fact::KNOWLEDGE, $text, $this->analyzer->keys($text), $trust, $provenance, null);
        if ($age > 0) {
            $fact = $this->store->facts[$fact->id] = $fact->with(updatedAt: $this->now - $age);
        }
        return $fact;
    }

    public function testNothingChangedNothingDone(): void {
        $this->add('Сервер pve-01 стоит в Хельсинки', age: 100);
        $this->consolidation->run();
        $this->now += 86400;

        self::assertSame('Память не менялась — проверять нечего.', $this->consolidation->run());
        self::assertSame(ConsolidationJournal::SKIPPED, $this->journal->passes[1]['status']);
        self::assertSame([], $this->told);
    }

    public function testDuplicateIsProposedOnceAndTheWeakerGoes(): void {
        $kept = $this->add('Бэкапы базы делаются каждую ночь на pve-02 в 3:00');
        $copy = $this->add('Каждую ночь в 3:00 делаются бэкапы базы на pve-02', 0.5, provenance: Fact::AGENT);
        $this->add('Сайт магазина работает на Bitrix');

        $this->consolidation->run();

        self::assertCount(1, $this->shown);
        self::assertSame(Proposal::FORGET, $this->shown[0]->kind);
        self::assertSame($copy->id, $this->shown[0]->factId);
        self::assertStringContainsString(sprintf('повторяет факт #%d', $kept->id), $this->shown[0]->reason);
        self::assertCount(1, $this->told);
        self::assertStringContainsString(sprintf('предложил забыть #%d', $copy->id), $this->told[0]);
        self::assertTrue($this->store->fact($copy->id)->active ?? false, 'nothing is forgotten without the owner');

        $this->now += 86400;
        $this->consolidation->run(true);
        self::assertCount(1, $this->shown, 'the same pair is not asked again');
    }

    public function testNumbersThatDifferAreAConflictNotADuplicate(): void {
        $this->add('Встреча с командой проходит по понедельникам в 11:00');
        $this->add('Встреча с командой проходит по понедельникам в 12:00');

        $text = $this->consolidation->run();

        self::assertSame([], $this->shown);
        self::assertStringContainsString('противоречат друг другу', $text);
        $this->now += 86400;
        self::assertStringNotContainsString('противоречат', $this->consolidation->run(true), 'paused for 14 days');
    }

    public function testANegationIsAConflictNotADuplicate(): void {
        // spec: brief/memory.md#консолидация-dreaming — facts that differ by a negation contradict each other
        $this->add('Владелец любит острую еду');
        $this->add('Владелец не любит острую еду', 0.5, provenance: Fact::AGENT);

        $text = $this->consolidation->run();

        self::assertSame([], $this->shown, 'not proposed to be forgotten as a copy');
        self::assertStringContainsString('противоречат друг другу (отрицание)', $text);
    }

    public function testAReportThatWasNotDeliveredIsShownNextTime(): void {
        // spec: brief/memory.md#консолидация-dreaming — a finding counts as shown only once the report reached the owner
        $this->add('Встреча с командой проходит по понедельникам в 11:00');
        $this->add('Встреча с командой проходит по понедельникам в 12:00');
        $this->delivered = false;
        $this->consolidation->run();
        self::assertSame(ConsolidationJournal::FAILED, $this->journal->passes[0]['status'], 'the next night looks again');

        $this->delivered = true;
        $this->now += 86400;
        self::assertStringContainsString('противоречат друг другу', $this->consolidation->run());
    }

    public function testAMissedNightIsCaughtUpAtStart(): void {
        // spec: brief/memory.md#консолидация-dreaming — a night missed while the app was down is checked at start
        $midnight = 1_800_000_000 - 1_800_000_000 % 86400;

        self::assertTrue(MemoryConsolidationModule::missed(null, 3, $midnight + 10 * 3600), 'never checked');
        self::assertTrue(MemoryConsolidationModule::missed($midnight - 86400 + 3 * 3600, 3, $midnight + 10 * 3600), 'last night was missed');
        self::assertFalse(MemoryConsolidationModule::missed($midnight + 3 * 3600 + 5, 3, $midnight + 10 * 3600), 'checked tonight');
        self::assertFalse(MemoryConsolidationModule::missed($midnight - 86400 + 3 * 3600, 3, $midnight + 2 * 3600), 'tonight has not come yet');
    }

    public function testOldUnusedFactsOfLowTrustAreAskedAbout(): void {
        $unused = $this->add('Владелец когда-то хотел купить велосипед', 0.4, age: 40 * 86400);
        $recalled = $this->add('Пароли хранятся в Bitwarden', 0.4, age: 40 * 86400);
        $this->add('Диск на pve-01 — NVMe', 0.4, age: 2 * 86400);
        $this->store->markRecalled(1, [$recalled->id]);

        $text = $this->consolidation->run();

        self::assertStringContainsString(sprintf('#%d Владелец когда-то хотел купить велосипед', $unused->id), $text);
        self::assertStringNotContainsString('Bitwarden', $text);
        self::assertStringNotContainsString('NVMe', $text);
    }

    public function testASharpDropOfMemoryRaisesAnAlertOfTheGuardEvenWithoutSleep(): void {
        // spec: brief/memory.md#страж-потерь — the guard is apart from sleep and runs always
        $guardJournal = new \Mercurius\Memory\Infrastructure\InMemoryGuardJournal();
        $guard = new \Mercurius\Memory\App\LossGuard($this->store, $guardJournal, function (string $text): bool {
            $this->told[] = $text;
            return $this->delivered;
        });
        $facts = [];
        foreach (['Первый факт про сервер', 'Второй факт про сайт', 'Третий факт про почту', 'Четвёртый факт про домен'] as $text) {
            $facts[] = $this->add($text);
        }
        $first = $guard->run();
        self::assertNull($first);
        $this->store->forgetFact($facts[0]->id, 'f1');
        $this->store->forgetFact($facts[1]->id, 'f2');

        $this->delivered = false;
        $alert = $guard->run();
        self::assertNotNull($alert);
        self::assertStringContainsString('Памяти стало заметно меньше: фактов было 4, стало 2', $alert);
        $this->delivered = true;
        $again = $guard->run();
        self::assertNotNull($again, 'an alert not delivered is compared again from the same snapshot');
        $after = $guard->run();
        self::assertNull($after, 'once told, the new size is the snapshot');
        self::assertStringNotContainsString('меньше', $this->consolidation->run(), 'the sleep does not tell of a loss');
    }

    public function testQuietMemoryIsNotReportedToTheOwner(): void {
        $this->add('Сервер pve-01 стоит в Хельсинки');

        self::assertSame('Проверено фактов: 1. Замечаний нет.', $this->consolidation->run());
        self::assertSame([], $this->told);
        self::assertSame(ConsolidationJournal::DONE, $this->journal->passes[0]['status']);
    }

    public function testTheNightlyHourIsComputedInUtc(): void {
        $midnight = 1_800_000_000 - 1_800_000_000 % 86400;

        self::assertSame(3 * 3600, MemoryConsolidationModule::secondsUntil(3, $midnight));
        self::assertSame(86400 - 3600, MemoryConsolidationModule::secondsUntil(3, $midnight + 4 * 3600));
        self::assertSame(86400, MemoryConsolidationModule::secondsUntil(3, $midnight + 3 * 3600));
    }
}
