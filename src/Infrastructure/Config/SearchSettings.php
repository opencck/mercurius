<?php

declare(strict_types=1);

namespace OpenCCK\Infrastructure\Config;

use Amp\Http\Client\HttpClient;
use Mercurius\Search\CachedSearch;
use Mercurius\Search\FallbackSearch;
use Mercurius\Search\Provider\BraveProvider;
use Mercurius\Search\Provider\OpenRouterProvider;
use Mercurius\Search\Provider\SearxngProvider;
use Mercurius\Search\Provider\TavilyProvider;
use Mercurius\Search\SearchProvider;

/**
 * Search providers from .env: SEARCH_PROVIDERS lists them in order (searxng, brave, tavily, openrouter); a provider
 * without its setting is skipped. Nothing set — OpenRouter web search when its key is there.
 *
 * spec: brief/participants.md#решения-владельца-о-правах — search through an interface with pluggable providers
 */
final class SearchSettings {
    public const NAMES = ['searxng', 'brave', 'tavily', 'openrouter'];

    /** What each provider is good for: the agent chooses by it. */
    public const ABOUT = [
        'searxng' => 'search.about.searxng',
        'brave' => 'search.about.brave',
        'tavily' => 'search.about.tavily',
        'openrouter' => 'search.about.openrouter',
    ];

    /**
     * @param array<array-key, mixed> $env
     * @return array{SearchProvider|null, list<string>, array<string, SearchProvider>} the search in order (null — none),
     *         the providers skipped for a missing setting, and each configured provider by name
     */
    public static function build(array $env, HttpClient $http, ?string $openRouterKey): array {
        $order = array_values(array_filter(array_map('trim', explode(',', strtolower(Env::raw($env, 'SEARCH_PROVIDERS') ?? 'openrouter'))), static fn(string $n): bool => $n !== ''));
        $providers = [];
        $skipped = [];
        foreach ($order as $name) {
            $provider = match ($name) {
                'searxng' => ($url = Env::raw($env, 'SEARXNG_URL')) === null ? null : new SearxngProvider($http, $url),
                'brave' => ($key = Env::raw($env, 'BRAVE_SEARCH_API_KEY')) === null ? null : new BraveProvider($http, $key),
                'tavily' => ($key = Env::raw($env, 'TAVILY_API_KEY')) === null ? null : new TavilyProvider($http, $key),
                'openrouter' => $openRouterKey === null ? null : new OpenRouterProvider($http, $openRouterKey, Env::raw($env, 'WEB_SEARCH_MODEL') ?? 'openai/gpt-5.6-luna'),
                default => throw ConfigException::invalid('SEARCH_PROVIDERS', 'a list of ' . implode(', ', self::NAMES)),
            };
            if ($provider === null) {
                $skipped[] = $name;
            } else {
                $providers[] = $provider;
            }
        }
        $each = [];
        foreach ($providers as $provider) {
            $each[$provider->name()] = new CachedSearch($provider);
        }
        return [$providers === [] ? null : new CachedSearch(new FallbackSearch($providers)), $skipped, $each];
    }
}
