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
            $name = self::openRouterId($name);
            if ($name !== null) {
                return $name;
            }
        }
        return null;
    }

    /**
     * The id of OpenRouter a name of the list stands for; null — the search cannot call it (a model of a subscription,
     * of another provider, or not a model id).
     *
     * @return ?non-empty-string
     */
    private static function openRouterId(string $name): ?string {
        $name = trim($name);
        // the provider takes the id of OpenRouter itself
        $name = str_starts_with($name, 'openrouter:') ? substr($name, strlen('openrouter:')) : $name;
        return $name !== '' && preg_match('#^[a-z0-9._-]+/[a-z0-9._:-]+$#i', $name) === 1 ? $name : null;
    }

    /**
     * What is wrong with a new list for the search: the names it cannot call. A list with such a name is refused when it
     * is set, not at the start — a stored list that does not fit leaves the search off, the other settings stand.
     *
     * spec: brief/models.md#списки-моделей-по-категориям — the search is the web search of a model of OpenRouter
     *
     * @return list<string> the names the search cannot call; empty — the list fits
     */
    public static function unfit(string $list): array {
        $bad = [];
        foreach (explode(',', $list) as $name) {
            if (trim($name) !== '' && self::openRouterId($name) === null) {
                $bad[] = trim($name);
            }
        }
        return $bad;
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
