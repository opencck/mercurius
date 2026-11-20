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
     * The first model of the owner's list for the search; null — none was named.
     *
     * @param array<array-key, mixed> $env
     * @return ?non-empty-string
     */
    private static function searchModel(array $env): ?string {
        foreach (explode(',', Env::raw($env, 'WEB_SEARCH_MODELS') ?? '') as $name) {
            $name = trim($name);
            // the provider takes the id of OpenRouter itself
            $name = str_starts_with($name, 'openrouter:') ? substr($name, strlen('openrouter:')) : $name;
            if ($name !== '' && preg_match('#^[a-z0-9._-]+/[a-z0-9._:-]+$#i', $name) === 1) {
                return $name;
            }
        }
        return null;
    }

    /**
     * @param array<array-key, mixed> $env
     * @return array{SearchProvider|null, list<string>, array<string, SearchProvider>} the search in order (null — none),
     *         the providers skipped for a missing setting, and each configured provider by name
     */
    public static function build(array $env, HttpClient $http, ?string $openRouterKey): array {
        $searchModel = self::searchModel($env);
        $order = array_values(array_filter(array_map('trim', explode(',', strtolower(Env::raw($env, 'SEARCH_PROVIDERS') ?? 'openrouter'))), static fn(string $n): bool => $n !== ''));
        $providers = [];
        $skipped = [];
        foreach ($order as $name) {
            $provider = match ($name) {
                'searxng' => ($url = Env::raw($env, 'SEARXNG_URL')) === null ? null : new SearxngProvider($http, $url),
                'brave' => ($key = Env::raw($env, 'BRAVE_SEARCH_API_KEY')) === null ? null : new BraveProvider($http, $key),
                'tavily' => ($key = Env::raw($env, 'TAVILY_API_KEY')) === null ? null : new TavilyProvider($http, $key),
                // spec: brief/models.md#списки-моделей-по-категориям — the search has no model until the owner names one
                'openrouter' => $openRouterKey === null || $searchModel === null ? null : new OpenRouterProvider($http, $openRouterKey, $searchModel),
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
