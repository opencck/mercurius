<?php

declare(strict_types=1);

namespace OpenCCK\Test\Unit\App\Agent\Model;

use DomainException;
use Mercurius\Llm\ModelCatalog\ModelInfo;
use OpenCCK\App\Agent\Model\ModelCatalogView;
use OpenCCK\App\Agent\Model\ProviderPauses;
use OpenCCK\Test\Support\InMemoryModelStores;
use PHPUnit\Framework\TestCase;

/**
 * The agent reads which providers of models it has and what each of them offers: the owner chooses the models of a
 * category from that, not from the lists that stand now.
 *
 * spec: brief/models.md#каталог-провайдеров-для-агента
 */
final class ModelCatalogViewTest extends TestCase {
    private InMemoryModelStores $catalog;
    private ProviderPauses $pauses;
    private ModelCatalogView $view;

    protected function setUp(): void {
        $this->catalog = new InMemoryModelStores();
        $this->catalog->replace('openrouter', [
            new ModelInfo('z-ai/glm-5.3-flash', 'GLM 5.3 Flash', 200000, 0.00000007, 0.0000003, ['text'], ['text'], ['tools'], 4096),
            new ModelInfo('nvidia/nemotron-vl:free', 'Nemotron VL', 131072, 0.0, 0.0, ['text', 'image'], ['text'], ['tools'], 4096),
            new ModelInfo('author/plain', 'Plain', 8000, 0.000001, 0.000002, ['text'], ['text'], [], 1024),
        ]);
        $this->catalog->replace('antigravity', [
            new ModelInfo('gemini-3.8-flash', 'gemini-3.8-flash', 60000, 0.0, 0.0, ['text'], ['text'], ['tools'], null),
        ]);
        $this->pauses = new ProviderPauses(null, static fn(): int => 1000);
        $this->view = new ModelCatalogView($this->catalog, ['openrouter', 'antigravity'], $this->pauses);
    }

    public function testTheProvidersTheAgentHasAreListedWithWhatEachIs(): void {
        $text = $this->view->providers();

        self::assertMatchesRegularExpression('~openrouter[^\n]*3~', $text, 'a provider by a key and the size of its catalog');
        self::assertMatchesRegularExpression('~antigravity[^\n]*/antigravity~', $text, 'a subscription names the command of its sign-in');
        self::assertStringContainsString('gemini', $text, 'a provider that is not connected is named too');
        self::assertStringContainsString('vertex', $text);
        self::assertSame(1, preg_match_all('~^- antigravity~m', $text), 'and a connected one is not said to be missing');
    }

    public function testAProviderTakenOffTheChoiceSaysSo(): void {
        $this->pauses->pause('antigravity', 4600, ProviderPauses::SIGN_IN);

        self::assertMatchesRegularExpression('~antigravity[^\n]*/antigravity login~', $this->view->providers());
    }

    public function testTheCatalogOfAProviderIsReadWithFullNames(): void {
        $text = $this->view->catalog('openrouter', null, null, 40);

        self::assertStringContainsString('openrouter:z-ai/glm-5.3-flash', $text, 'the name is the one a list takes');
        self::assertStringContainsString('openrouter:nvidia/nemotron-vl:free', $text);
        self::assertMatchesRegularExpression('~nemotron-vl:free[^\n]*image~', $text, 'what the model reads is told');
        self::assertMatchesRegularExpression('~glm-5.3-flash[^\n]*0\.07[^\n]*0\.3~', $text, 'the price is per million tokens');
        self::assertMatchesRegularExpression('~glm-5.3-flash[^\n]*tools~', $text);
        self::assertDoesNotMatchRegularExpression('~author/plain[^\n]*tools~', $text);
    }

    public function testTheCatalogIsNarrowedByWordsAndByWhatTheModelReads(): void {
        self::assertStringNotContainsString('nemotron', $this->view->catalog('openrouter', 'GLM flash', null, 40), 'words are looked for in the id and the name');
        $vision = $this->view->catalog('openrouter', null, 'image', 40);
        self::assertStringContainsString('nemotron-vl', $vision);
        self::assertStringNotContainsString('glm', $vision);
    }

    public function testNothingFoundIsSaidInWords(): void {
        $text = $this->view->catalog('antigravity', null, 'image', 40);

        self::assertStringNotContainsString('antigravity:gemini', $text);
        self::assertStringContainsString('image', $text, 'the model learns that no model of the plan reads pictures');
    }

    public function testALongCatalogIsCutAndSaysHowManyThereAre(): void {
        $text = $this->view->catalog('openrouter', null, null, 2);

        self::assertSame(2, preg_match_all('~^- openrouter:~m', $text));
        self::assertStringContainsString('3', $text, 'the count of all that fit');
    }

    public function testAProviderTheAgentDoesNotHaveIsRefusedWithTheOnesItHas(): void {
        try {
            $this->view->catalog('gemini', null, null, 40);
            self::fail('a provider without a key has no catalog to read');
        } catch (DomainException $e) {
            self::assertStringContainsString('openrouter', $e->getMessage());
            self::assertStringContainsString('antigravity', $e->getMessage());
        }
    }
}
