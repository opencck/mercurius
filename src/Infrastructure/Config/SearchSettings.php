<?php

declare(strict_types=1);

namespace OpenCCK\Infrastructure\Config;

use Amp\Http\Client\HttpClient;
use Mercurius\Search\CachedSearch;
use Mercurius\Search\FallbackSearch;
use Mercurius\Search\Provider\BraveProvider;
use Mercurius\Search\Provider\GeminiProvider;
use Mercurius\Search\Provider\OpenAiProvider;
use Mercurius\Search\Provider\OpenRouterProvider;
use Mercurius\Search\Provider\SearxngProvider;
use Mercurius\Search\Provider\TavilyProvider;
use Mercurius\Search\SearchProvider;
use OpenCCK\Domain\Agent\ModelRef;

/**
 * Search providers from .env: SEARCH_PROVIDERS lists them in order (searxng, brave, tavily, model); a provider
 * without its setting is skipped. Nothing set — the search of the models of WEB_SEARCH_MODELS.
 *
 * The slot model (its old name openrouter) is the search of models with a web search of their provider: the models of
 * WEB_SEARCH_MODELS in their order, each while its provider has a key — OpenRouter (the web plugin), Gemini by a key
 * (Google Search) and OpenAI by a key (the web search of the Responses API).
 *
 * spec: brief/participants.md#решения-владельца-о-правах — search through an interface with pluggable providers
 * spec: brief/models.md#модели-поиска — which providers of models search the web
 */
final class SearchSettings {
    public const NAMES = ['searxng', 'brave', 'tavily', 'model', 'openrouter'];
    /** the providers of models that search the web by a key */
    public const MODEL_PROVIDERS = [ModelRef::OPENROUTER, ModelRef::GEMINI, ModelRef::OPENAI];

    /** What each provider is good for: the agent chooses by it. */
    public const ABOUT = [
        'searxng' => 'search.about.searxng',
        'brave' => 'search.about.brave',
        'tavily' => 'search.about.tavily',
        'openrouter' => 'search.about.openrouter',
        'gemini' => 'search.about.gemini',
        'openai' => 'search.about.openai',
    ];

    /**
     * The models of the owner's list for the search the search can call, in their order.
     *
     * @param array<array-key, mixed> $env
     * @return list<array{string, string}> the provider and the model id of the provider
     */
    private static function searchModels(array $env): array {
        $models = [];
        foreach (explode(',', Env::raw($env, 'WEB_SEARCH_MODELS') ?? '') as $name) {
            $model = self::model($name);
            if ($model !== null) {
                $models[] = $model;
            }
        }
        return $models;
    }

    /**
     * The provider and the model a name of the list stands for; null — the search cannot call it (a model of a
     * subscription, of a provider without a web search, or not a model id).
     *
     * @return ?array{string, string}
     */
    private static function model(string $name): ?array {
        $name = trim($name);
        if ($name === '') {
            return null;
        }
        $provider = ModelRef::OPENROUTER;
        // a bare author/model is of OpenRouter, as everywhere in the lists
        if (preg_match('#^([a-z]+):(.+)$#', $name, $m) === 1 && !str_contains($m[1], '/')) {
            [$provider, $name] = [$m[1], $m[2]];
        }
        if ($provider === ModelRef::OPENROUTER) {
            return preg_match('#^[a-z0-9._-]+/[a-z0-9._:-]+$#i', $name) === 1 ? [$provider, $name] : null;
        }
        if (in_array($provider, [ModelRef::GEMINI, ModelRef::OPENAI], true)) {
            return preg_match('#^[a-z0-9._-]+$#i', $name) === 1 ? [$provider, $name] : null;
        }
        return null;
    }

    /**
     * What is wrong with a new list for the search: the names it cannot call. A list with such a name is refused when it
     * is set, not at the start — a stored list that does not fit leaves the search off, the other settings stand.
     *
     * spec: brief/models.md#модели-поиска — the search is the web search of a model of a provider that has one
     *
     * @return list<string> the names the search cannot call; empty — the list fits
     */
    public static function unfit(string $list): array {
        $bad = [];
        foreach (explode(',', $list) as $name) {
            if (trim($name) !== '' && self::model($name) === null) {
                $bad[] = trim($name);
            }
        }
        return $bad;
    }

    /** The provider of a name of the list for the search; null — the search cannot call it. */
    public static function providerOf(string $name): ?string {
        return self::model($name)[0] ?? null;
    }

    /**
     * @param array<array-key, mixed> $env
     * @param array<string, ?string> $keys the keys of the providers of models: openrouter, gemini, openai
     * @return array{SearchProvider|null, list<string>, array<string, SearchProvider>} the search in order (null — none),
     *         the providers skipped for a missing setting, and each configured provider by name
     */
    public static function build(array $env, HttpClient $http, array $keys): array {
        $order = array_values(array_filter(array_map('trim', explode(',', strtolower(Env::raw($env, 'SEARCH_PROVIDERS') ?? 'model'))), static fn(string $n): bool => $n !== ''));
        $providers = [];
        $skipped = [];
        foreach ($order as $name) {
            $found = match ($name) {
                'searxng' => ($url = Env::raw($env, 'SEARXNG_URL')) === null ? [] : [new SearxngProvider($http, $url)],
                'brave' => ($key = Env::raw($env, 'BRAVE_SEARCH_API_KEY')) === null ? [] : [new BraveProvider($http, $key)],
                'tavily' => ($key = Env::raw($env, 'TAVILY_API_KEY')) === null ? [] : [new TavilyProvider($http, $key)],
                // spec: brief/models.md#списки-моделей-по-категориям — the search has no model until the owner names one
                'model', 'openrouter' => self::models($env, $http, $keys),
                default => throw ConfigException::invalid('SEARCH_PROVIDERS', 'a list of ' . implode(', ', self::NAMES)),
            };
            if ($found === []) {
                $skipped[] = $name;
            }
            array_push($providers, ...$found);
        }
        $each = [];
        foreach ($providers as $provider) {
            $each[$provider->name()] ??= new CachedSearch($provider);
        }
        return [$providers === [] ? null : new CachedSearch(new FallbackSearch($providers)), $skipped, $each];
    }

    /**
     * The search of the models of the list, each while its provider has a key; the first model of a provider only.
     *
     * @param array<array-key, mixed> $env
     * @param array<string, ?string> $keys
     * @return list<SearchProvider>
     */
    private static function models(array $env, HttpClient $http, array $keys): array {
        $found = [];
        foreach (self::searchModels($env) as [$provider, $model]) {
            $key = $keys[$provider] ?? null;
            if ($key === null || $key === '' || isset($found[$provider])) {
                continue;
            }
            $found[$provider] = match ($provider) {
                ModelRef::GEMINI => new GeminiProvider($http, $key, $model),
                ModelRef::OPENAI => new OpenAiProvider($http, $key, $model),
                default => new OpenRouterProvider($http, $key, $model),
            };
        }
        return array_values($found);
    }
}
