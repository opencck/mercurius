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
use OpenCCK\App\Agent\Tool\WebSearchTool;
use OpenCCK\Domain\Entity\Principal;
use OpenCCK\Infrastructure\Config\ConfigException;
use OpenCCK\Infrastructure\Config\SearchSettings;
use OpenCCK\Test\Support\InMemoryPrincipalRepository;

final class SearchSettingsTest extends AsyncTestCase {
    public function testProvidersFollowTheOrderAndThoseWithoutSettingsAreSkipped(): void {
        $http = HttpClientBuilder::buildDefault();

        [$search, $skipped] = SearchSettings::build(['SEARCH_PROVIDERS' => 'searxng, brave,openrouter', 'SEARXNG_URL' => 'http://searxng:8080'], $http, 'sk-or');
        self::assertSame('searxng>openrouter', $search?->name());
        self::assertSame(['brave'], $skipped);

        [$default] = SearchSettings::build([], $http, 'sk-or');
        self::assertSame('openrouter', $default?->name(), 'OpenRouter by default');
        self::assertNull(SearchSettings::build([], $http, null)[0], 'no key, no search');

        $this->expectException(ConfigException::class);
        SearchSettings::build(['SEARCH_PROVIDERS' => 'google'], $http, null);
    }

    public function testTheToolGivesTheTextOfTheSearchAndItsErrors(): void {
        $owner = (new InMemoryPrincipalRepository())->ensureOwner('telegram', '42', 'Owner');
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
