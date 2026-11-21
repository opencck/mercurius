<?php

declare(strict_types=1);

namespace OpenCCK\Test\Unit\Memory;

use Amp\PHPUnit\AsyncTestCase;
use Mercurius\Memory\App\CachedJudge;
use Mercurius\Memory\App\Consolidation;
use Mercurius\Memory\App\Enrichment;
use Mercurius\Memory\App\Proposals;
use Mercurius\Memory\Domain\ContentScreen;
use Mercurius\Memory\Domain\Fact;
use Mercurius\Memory\Domain\Proposal;
use Mercurius\Memory\Domain\SleepEnricher;
use Mercurius\Memory\Domain\SleepJudge;
use Mercurius\Memory\Domain\SleepMerger;
use Mercurius\Memory\Domain\TextAnalyzer;
use Mercurius\Memory\Domain\Verdict;
use Mercurius\Memory\Infrastructure\InMemoryConsolidationJournal;
use Mercurius\Memory\Infrastructure\InMemoryMemoryStore;
use Mercurius\Memory\Infrastructure\InMemoryVerdictCache;
use OpenCCK\App\Agent\LlmSleepJudge;
use OpenCCK\Test\Support\ScriptedLlm;
use Psr\Log\NullLogger;

/**
 * The model of sleep on the gray zone: copies become cards, a false conflict of the code is dropped, a model that does
 * not answer leaves the night to the code; verdicts are paid for once.
 *
 * spec: brief/memory.md#судья-пары
 */
final class SleepJudgeTest extends AsyncTestCase {
    private InMemoryMemoryStore $store;
    private InMemoryConsolidationJournal $journal;
    private TextAnalyzer $analyzer;
    /** @var list<Proposal> */
    private array $shown = [];

    protected function setUp(): void {
        parent::setUp();
        $this->store = new InMemoryMemoryStore();
        $this->journal = new InMemoryConsolidationJournal();
        $this->analyzer = new TextAnalyzer();
    }

    private function add(string $text): Fact {
        return $this->store->addFact('owner', Fact::KNOWLEDGE, $text, $this->analyzer->keys($text), 0.8, Fact::OWNER_SAID, null);
    }

    /** @param \Closure(Fact, Fact): ?Verdict $say */
    private function night(\Closure $say): string {
        $consolidation = new Consolidation($this->store, new Proposals($this->store, $this->analyzer, new ContentScreen(), 'k'), $this->journal, function (Proposal $p): bool {
            $this->shown[] = $p;
            return true;
        }, static fn(string $t): bool => true);
        $consolidation->useJudge(new class($say) implements SleepJudge {
            public int $asked = 0;

            /** @param \Closure(Fact, Fact): ?Verdict $say */
            public function __construct(private readonly \Closure $say) {}

            public function judge(array $pairs): array {
                $this->asked += count($pairs);
                return array_map(fn(array $p): ?Verdict => ($this->say)($p[0], $p[1]), $pairs);
            }

            public function spent(): array {
                return [1, 500, 0.001];
            }
        });
        return $consolidation->run(true);
    }

    public function testACopyByMeaningBecomesACardAndAFalseConflictIsDropped(): void {
        $this->add('Владелец живёт в Паттайе');
        $this->add('Владелец переехал в Таиланд и живёт в Паттайе');
        $this->add('Сервер pve-01 стоит в Хельсинки, 128 ГБ памяти');
        $this->add('Сервер pve-01 стоит в Хельсинки, 64 ГБ памяти, это другой сервер');

        $report = $this->night(static fn(Fact $a, Fact $b): Verdict => str_contains($a->text, 'Паттай')
            ? new Verdict(Verdict::SAME, 0.9)
            : new Verdict(Verdict::UNRELATED, 0.8));

        self::assertCount(1, $this->shown, 'a card «forget» for the copy by meaning');
        self::assertSame(Proposal::FORGET, $this->shown[0]->kind);
        self::assertStringNotContainsString('128', $report, 'the code\'s conflict of numbers the model ruled out is not shown');
        self::assertStringContainsString(sprintf(\Mercurius\I18n\t('app.consolidation.model_spent'), 1, 500, 0.001), $report);
        self::assertSame(1, $this->journal->passes[0]['calls'] ?? null, 'the calls go into the journal');
    }

    public function testAModelThatDoesNotAnswerLeavesTheNightToTheCode(): void {
        $this->add('Владелец любит острую еду');
        $this->add('Владелец не выносит острую еду');

        $report = $this->night(static fn(Fact $a, Fact $b): ?Verdict => null);

        self::assertStringContainsString(\Mercurius\I18n\t('app.consolidation.model_silent'), $report);
    }

    public function testAVerdictIsPaidForOnce(): void {
        $a = $this->add('Владелец живёт в Паттайе');
        $b = $this->add('Владелец переехал в Таиланд и живёт в Паттайе');
        $inner = new class implements SleepJudge {
            public int $asked = 0;

            public function judge(array $pairs): array {
                $this->asked += count($pairs);
                return array_map(static fn(array $p): Verdict => new Verdict(Verdict::SAME, 0.9), $pairs);
            }

            public function spent(): array {
                return [0, 0, 0.0];
            }
        };
        $judge = new CachedJudge($inner, $cache = new InMemoryVerdictCache(), '1');

        $judge->judge([[$a, $b]]);
        $again = $judge->judge([[$a, $b]]);

        self::assertSame(1, $inner->asked);
        self::assertSame(Verdict::SAME, $again[0]?->label);
        $cache->forgetFact($a->id);
        self::assertSame([], $cache->verdicts, 'a forgotten fact leaves the cache');
    }

    /**
     * @param \Closure(Fact, Fact): ?Verdict $say
     * @param list<?string> $texts what the model writes for the pairs to merge
     */
    private function nightWithMerges(\Closure $say, array $texts): string {
        $consolidation = new Consolidation($this->store, new Proposals($this->store, $this->analyzer, new ContentScreen(), 'k'), $this->journal, function (Proposal $p): bool {
            $this->shown[] = $p;
            return true;
        }, static fn(string $t): bool => true);
        $model = new class($say, $texts) implements SleepJudge, SleepMerger {
            /**
             * @param \Closure(Fact, Fact): ?Verdict $say
             * @param list<?string> $texts
             */
            public function __construct(private readonly \Closure $say, private readonly array $texts) {}

            public function judge(array $pairs): array {
                return array_map(fn(array $p): ?Verdict => ($this->say)($p[0], $p[1]), $pairs);
            }

            public function merge(array $pairs): array {
                return array_slice($this->texts, 0, count($pairs));
            }

            public function spent(): array {
                return [0, 0, 0.0];
            }
        };
        $consolidation->useJudge($model);
        $consolidation->useMerger($model);
        return $consolidation->run(true);
    }

    public function testADetailAddedBecomesACardMergeAndARefusedTextNone(): void {
        // spec: brief/memory.md#слияние
        $this->add('Сервер pve-01 стоит в Хельсинки, в стойке у окна');
        $this->add('Сервер pve-01 стоит в Хельсинки, у него 128 ГБ памяти');
        $detail = static fn(Fact $a, Fact $b): Verdict => new Verdict(Verdict::ADDS_DETAIL, 0.9);

        $report = $this->nightWithMerges($detail, ['Сервер pve-01 стоит в Хельсинки, в стойке у окна, у него 128 ГБ памяти']);

        self::assertCount(1, $this->shown);
        self::assertSame(Proposal::MERGE, $this->shown[0]->kind);
        self::assertNotNull($this->shown[0]->mergeWith);
        self::assertStringContainsString(sprintf(\Mercurius\I18n\t('app.consolidation.merge_card'), $this->shown[0]->factId, $this->shown[0]->mergeWith, $this->shown[0]->id), $report);

        $this->store = new InMemoryMemoryStore();
        $this->journal = new InMemoryConsolidationJournal();
        $this->shown = [];
        $this->add('Сервер pve-01 стоит в Хельсинки, в стойке у окна');
        $this->add('Сервер pve-01 стоит в Хельсинки, у него 128 ГБ памяти');
        $report = $this->nightWithMerges($detail, ['Сервер pve-01 стоит в Хельсинки, у него 64 ГБ памяти']);

        self::assertSame([], $this->shown, 'a text that changed a number does not reach the owner');
        self::assertStringContainsString(sprintf(\Mercurius\I18n\t('app.consolidation.merge_refused'), 1), $report);
    }

    public function testTheModelWritesAMergeAndAFactWithASecretIsNotSent(): void {
        $a = $this->add('Сервер pve-01 стоит в Хельсинки');
        $b = $this->add('У pve-01 128 ГБ памяти');
        $secret = $this->add('Пароль от pve-01: sk-or-v1-0123456789abcdef0123456789abcdef0123456789abcdef');
        $llm = new ScriptedLlm([ScriptedLlm::text('{"merged": [{"pair": 1, "text": "Сервер pve-01 стоит в Хельсинки, 128 ГБ памяти"}, {"pair": 5, "text": "x"}]}')]);
        $judge = new LlmSleepJudge($llm, static fn(): array => ['openrouter:first/model'], new NullLogger());

        $texts = $judge->merge([[$a, $secret], [$a, $b]]);

        self::assertSame([null, 'Сервер pve-01 стоит в Хельсинки, 128 ГБ памяти'], $texts);
        self::assertCount(1, $llm->requests);
        self::assertStringNotContainsString('sk-or-v1', $llm->requests[0]->messages[1]->content);
        self::assertSame(1, $judge->spent()[0]);
    }

    public function testTheModelGivesSearchWordsAndTheNightSaysSoEvenWhenMemoryDidNotChange(): void {
        // spec: brief/memory.md#ночное-обогащение
        $a = $this->add('Сервер pve-01 стоит в Хельсинки');
        $secret = $this->add('Пароль от pve-01: sk-or-v1-0123456789abcdef0123456789abcdef0123456789abcdef');
        $llm = new ScriptedLlm([
            ScriptedLlm::text('{"facts": [{"fact": 1, "words": ["гипервизор", 7], "spellings": [["pve", "proxmox"], ["x"]]}, {"fact": 9, "words": ["x"]}]}'),
            ScriptedLlm::text('{"facts": []}'),
        ]);
        $judge = new LlmSleepJudge($llm, static fn(): array => ['openrouter:first/model'], new NullLogger());

        $found = $judge->enrich([$secret, $a], true);

        self::assertSame([null, [['гипервизор'], [['pve', 'proxmox']]]], $found);
        self::assertStringNotContainsString('sk-or-v1', $llm->requests[0]->messages[1]->content);
        self::assertStringContainsString('Chinese', $llm->requests[0]->messages[0]->content, 'translations asked only when on');
        $judge->enrich([$a], false);
        self::assertStringNotContainsString('Chinese', $llm->requests[1]->messages[0]->content);

        $consolidation = new Consolidation($this->store, new Proposals($this->store, $this->analyzer, new ContentScreen(), 'k'), $this->journal, static fn(Proposal $p): bool => true, static fn(string $t): bool => true);
        $consolidation->run(true);
        $consolidation->useEnrichment(new Enrichment($this->store, $this->analyzer, new ContentScreen(), new class implements SleepEnricher {
            public function enrich(array $facts, bool $translate): array {
                return array_map(static fn(Fact $f): array => [['гипервизор'], []], $facts);
            }

            public function spent(): array {
                return [1, 300, 0.002];
            }
        }, static fn(): bool => false));

        $report = $consolidation->run();

        self::assertStringContainsString(sprintf(\Mercurius\I18n\t('app.consolidation.enriched'), 2, 1), $report, 'the night ran though memory did not change; a word in another script than the fact is not kept');
        self::assertStringContainsString(sprintf(\Mercurius\I18n\t('app.consolidation.model_spent'), 1, 300, 0.002), $report);
    }

    public function testTheModelsAnswerIsReadAndAFailingModelGivesWayToTheNext(): void {
        $a = $this->add('Владелец живёт в Паттайе');
        $b = $this->add('Владелец переехал в Таиланд и живёт в Паттайе');
        $llm = new ScriptedLlm([
            new \Mercurius\Llm\LlmException(\Mercurius\Llm\ErrorKind::RateLimited, 'down'),
            ScriptedLlm::text('{"verdicts": [{"pair": 1, "label": "same", "confidence": 0.95, "newer": null}, {"pair": 7, "label": "same", "confidence": 1}]}'),
        ]);
        $judge = new LlmSleepJudge($llm, static fn(): array => ['openrouter:first/model', 'openrouter:second/model'], new NullLogger());

        $verdicts = $judge->judge([[$a, $b]]);

        self::assertSame(Verdict::SAME, $verdicts[0]?->label);
        self::assertSame(['openrouter:first/model', 'openrouter:second/model'], array_map(static fn($r) => $r->model, $llm->requests));
        self::assertSame(1, $judge->spent()[0], 'only an answered call counts');
        self::assertSame([], LlmSleepJudge::verdicts('{"verdicts": [{"pair": 1, "label": "maybe", "confidence": 2}]}', [0]), 'an answer outside the schema is not used');
    }
}
