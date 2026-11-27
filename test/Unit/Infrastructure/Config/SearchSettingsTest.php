<?php

declare(strict_types=1);

namespace OpenCCK\Test\Unit\Infrastructure\Config;

use Amp\Http\Client\HttpClientBuilder;
use Amp\NullCancellation;
use Amp\PHPUnit\AsyncTestCase;
use Mercurius\Search\SearchException;
use Mercurius\Search\SearchProvider;
use Mercurius\Search\SearchResult;
use OpenCCK\App\Agent\Tool\ToolContext;
use OpenCCK\App\Agent\Tool\Web\WebSearchTool;
use OpenCCK\Domain\Entity\Member;
use OpenCCK\Infrastructure\Config\ConfigException;
use OpenCCK\Infrastructure\Config\SearchSettings;
use OpenCCK\Test\Support\InMemoryMemberRepository;

final class SearchSettingsTest extends AsyncTestCase {
    public function testProvidersFollowTheOrderAndThoseWithoutSettingsAreSkipped(): void {
        $http = HttpClientBuilder::buildDefault();

        [$search, $skipped] = SearchSettings::build(['SEARCH_PROVIDERS' => 'searxng, brave,openrouter', 'SEARXNG_URL' => 'http://searxng:8080', 'WEB_SEARCH_MODELS' => 'openrouter:author/search, author/other'], $http, ['openrouter' => 'sk-or']);
        self::assertSame('searxng>openrouter', $search?->name());
        self::assertSame(['brave'], $skipped);

        // spec: brief/models.md#списки-моделей-по-категориям — the search has no model until the owner names one
        [$none, $skippedNone] = SearchSettings::build([], $http, ['openrouter' => 'sk-or']);
        self::assertNull($none, 'a key alone is not a search: no model was named');
        self::assertSame(['model'], $skippedNone, 'the slot of the models is the default');
        [$named] = SearchSettings::build(['WEB_SEARCH_MODELS' => 'author/search'], $http, ['openrouter' => 'sk-or']);
        self::assertSame('openrouter', $named?->name());
        self::assertNull(SearchSettings::build([], $http, [])[0], 'no key, no search');

        $this->expectException(ConfigException::class);
        SearchSettings::build(['SEARCH_PROVIDERS' => 'google'], $http, []);
    }

    public function testTheModelsOfTheSearchMayBeOfGeminiAndOpenAiByTheirKeys(): void {
        // spec: brief/models.md#модели-поиска — the search is not of OpenRouter only: a model of a provider with web search
        $http = HttpClientBuilder::buildDefault();
        $list = ['WEB_SEARCH_MODELS' => 'gemini:gemini-3.8-flash, openai:gpt-4.1-mini, author/search'];

        [$search, $skipped, $each] = SearchSettings::build($list, $http, ['gemini' => 'AIza', 'openai' => 'sk-oa']);
        self::assertSame('gemini>openai', $search?->name(), 'in the order of the list; a model without its key is left out');
        self::assertSame([], $skipped);
        self::assertSame(['gemini', 'openai'], array_keys($each));

        [$all] = SearchSettings::build($list, $http, ['gemini' => 'AIza', 'openai' => 'sk-oa', 'openrouter' => 'sk-or']);
        self::assertSame('gemini>openai>openrouter', $all?->name());
        [$model] = SearchSettings::build(['SEARCH_PROVIDERS' => 'model'] + $list, $http, ['openai' => 'sk-oa']);
        self::assertSame('openai', $model?->name(), 'model is the name of the slot, openrouter its old name');

        self::assertSame([], SearchSettings::unfit('gemini:gemini-3.8-flash, openai:gpt-4.1-mini, openrouter:a/b, a/b'));
        self::assertSame(['antigravity:gemini-3.8-flash', 'claude:claude-opus-5-5', 'vertex:google/gemini-3.8-flash', 'chatgpt:gpt-5'],
            SearchSettings::unfit('antigravity:gemini-3.8-flash, claude:claude-opus-5-5, vertex:google/gemini-3.8-flash, chatgpt:gpt-5'));
    }

    public function testTheToolGivesTheTextOfTheSearchAndItsErrors(): void {
        $owner = (new InMemoryMemberRepository())->ensureOwner('telegram', '42', 'Owner');
        $context = new ToolContext(1, 1, $owner, new NullCancellation());
        $ok = new WebSearchTool(new class implements SearchProvider {
            public function name(): string {
                return 'fake';
            }

            public function search(string $query, int $limit = 5, ?\Amp\Cancellation $cancellation = null): SearchResult {
                return new SearchResult('fake', [new \Mercurius\Search\SearchHit('PHP', 'https://php.net', '', 1)], 'В ноябре.');
            }
        });
        $failing = new WebSearchTool(new class implements SearchProvider {
            public function name(): string {
                return 'fake';
            }

            public function search(string $query, int $limit = 5, ?\Amp\Cancellation $cancellation = null): SearchResult {
                throw new SearchException('Поиск не удался: Brave: HTTP 429');
            }
        });

        self::assertSame("В ноябре.\n\nИсточники:\n1. PHP — https://php.net", $ok->execute(['query' => 'php 8.6'], $context)->content);
        $error = $failing->execute(['query' => 'php'], $context);
        self::assertTrue($error->isError);
        self::assertStringContainsString('HTTP 429', $error->content);
        self::assertTrue($ok->untrusted());
    }
}
