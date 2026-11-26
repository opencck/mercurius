<?php

declare(strict_types=1);

namespace OpenCCK\Infrastructure\Config;

use InvalidArgumentException;
use Mercurius\Llm\Gemini\VertexClient;
use OpenCCK\Domain\Agent\ModelRef;
use OpenCCK\Domain\Agent\ProviderProfile;

use SensitiveParameter;

/**
 * Model provider settings: the key of each provider of {@see ProviderProfile}, the route, the default models. The route is VLESS when VLESS_URI is set, direct otherwise.
 *
 * spec: brief/decisions.md#решения — route to the model through VLESS_URI
 */
final class LlmSettings {
    private const EFFORTS = ['none', 'minimal', 'low', 'medium', 'high', 'xhigh', 'max'];

    /** us-east-1 serves every Claude model of Bedrock. */
    private const BEDROCK_REGION = 'us-east-1';

    /** @var array<string, string> provider => key, only the providers with a key, in the order of the profiles */
    private readonly array $keys;
    private readonly ?string $routeUri;

    /**
     * @param non-empty-string $model
     * @param 'none'|'minimal'|'low'|'medium'|'high'|'xhigh'|'max' $reasoningEffort
     * @param positive-int $maxTokens
     * @param positive-int $historyLimit
     * @param positive-int $contextWindow tokens of the model's window; the model catalog will replace it
     * @param non-empty-string $cheapModel model of auxiliary calls (compaction); the model roles will replace it
     * @param array<string, string> $keys provider => key
     */
    private function __construct(
        #[SensitiveParameter] array $keys,
        #[SensitiveParameter] ?string $routeUri,
        public readonly string $model,
        public readonly string $reasoningEffort,
        public readonly int $maxTokens,
        public readonly int $historyLimit,
        public readonly int $contextWindow,
        public readonly string $cheapModel,
        /** AWS region of the Bedrock endpoint, checked to be a region name. */
        public readonly string $bedrockRegion = self::BEDROCK_REGION,
        /** Google Cloud project of Vertex; empty — the project of the service account. */
        public readonly string $vertexProject = '',
        /** Region of the Vertex endpoint, checked to be `global` or a region name. */
        public readonly string $vertexRegion = VertexClient::DEFAULT_REGION,
    ) {
        $this->keys = $keys;
        $this->routeUri = $routeUri;
    }

    /**
     * @param array<array-key, mixed> $env
     * @throws ConfigException
     */
    public static function fromEnv(array $env): self {
        // spec: brief/models.md#провайдеры-моделей-openrouter-и-openai — any key is enough, one of them is needed
        $keys = [];
        foreach (ProviderProfile::all() as $name => $profile) {
            $key = Env::raw($env, $profile->keyEnv);
            if ($key !== null) {
                $keys[$name] = $key;
            }
        }
        $providers = array_keys($keys);
        if ($providers === []) {
            $names = array_values(array_unique(array_map(static fn(ProviderProfile $p): string => $p->keyEnv, ProviderProfile::all())));
            throw ConfigException::invalid($names[0], sprintf('set one of %s: the agent needs at least one model provider', implode(', ', $names)));
        }
        // The region becomes a host name: only a region name may go there.
        $region = Env::raw($env, 'BEDROCK_REGION') ?? self::BEDROCK_REGION;
        if (preg_match('/^[a-z]{2}(-[a-z]+)+-\d{1,2}$/', $region) !== 1) {
            throw ConfigException::invalid('BEDROCK_REGION', 'an AWS region like us-east-1');
        }
        // spec: brief/models.md#провайдеры-gemini-и-vertex — the project and the region become parts of an address
        $vertexRegion = Env::raw($env, 'VERTEX_REGION') ?? VertexClient::DEFAULT_REGION;
        if (preg_match(VertexClient::REGION_PATTERN, $vertexRegion) !== 1) {
            throw ConfigException::invalid('VERTEX_REGION', 'global or a Google Cloud region like us-central1');
        }
        $vertexProject = Env::raw($env, 'VERTEX_PROJECT_ID') ?? '';
        if ($vertexProject !== '' && preg_match(VertexClient::PROJECT_PATTERN, $vertexProject) !== 1) {
            throw ConfigException::invalid('VERTEX_PROJECT_ID', 'a Google Cloud project id like my-project-123');
        }
        if (isset($keys[ModelRef::VERTEX])) {
            $problem = self::vertexProblem($keys[ModelRef::VERTEX], $vertexProject);
            if ($problem !== null) {
                throw ConfigException::invalid('VERTEX_CREDENTIALS', $problem);
            }
        }
        $first = ProviderProfile::all()[$providers[0]];
        $model = self::model($env, 'LLM_MODEL', self::bare($first->model), $providers);
        $window = Env::positiveInt($env, 'LLM_CONTEXT_WINDOW', 131_072, 10_000_000);
        if ($window < 4096) {
            throw ConfigException::invalid('LLM_CONTEXT_WINDOW', 'at least 4096 tokens');
        }

        return new self(
            keys: $keys,
            routeUri: Env::raw($env, 'VLESS_URI'),
            model: $model,
            reasoningEffort: self::effort(Env::oneOf($env, 'LLM_REASONING_EFFORT', self::EFFORTS, 'low')),
            maxTokens: Env::positiveInt($env, 'LLM_MAX_TOKENS', 4096, 200_000),
            historyLimit: Env::positiveInt($env, 'LLM_HISTORY_LIMIT', 40, 1000),
            contextWindow: $window,
            // spec: brief/models.md#списки-моделей-по-категориям — no model of its own by default: the starting model serves
            cheapModel: self::model($env, 'LLM_CHEAP_MODEL', $model, $providers),
            bedrockRegion: $region,
            vertexProject: $vertexProject,
            vertexRegion: $vertexRegion,
        );
    }

    /**
     * What is wrong with the JSON of a service account, in words that never repeat it; null when it can sign.
     */
    private static function vertexProblem(#[SensitiveParameter] string $json, string $project): ?string {
        $data = json_decode($json, true, 8);
        if (!is_array($data) || ($data['type'] ?? null) !== 'service_account') {
            return 'the JSON key file of a Google Cloud service account, in one line (type "service_account")';
        }
        $email = $data['client_email'] ?? null;
        $pem = $data['private_key'] ?? null;
        if (!is_string($email) || $email === '' || !is_string($pem) || $pem === '') {
            return 'a service account key with client_email and private_key';
        }
        $key = openssl_pkey_get_private($pem);
        // the queue of OpenSSL errors may quote the key: it is dropped unread
        while (openssl_error_string() !== false) {
        }
        if ($key === false) {
            return 'a service account key whose private_key is a readable private key (keep the \\n of the file as they are)';
        }
        /** @var mixed $own */
        $own = $data['project_id'] ?? null;
        if ($project === '' && (!is_string($own) || preg_match(VertexClient::PROJECT_PATTERN, $own) !== 1)) {
            return 'a service account key with project_id, or VERTEX_PROJECT_ID set';
        }
        return null;
    }

    /**
     * @param array<array-key, mixed> $env
     * @param non-empty-string $default
     * @param list<string> $providers the providers with a key
     * @return non-empty-string
     * @throws ConfigException
     */
    private static function model(array $env, string $name, string $default, array $providers): string {
        $model = Env::raw($env, $name) ?? $default;
        try {
            $ref = ModelRef::parse($model);
        } catch (InvalidArgumentException) {
            throw ConfigException::invalid($name, 'a model like author/slug (OpenRouter) or provider:id');
        }
        if ($ref->provider === ModelRef::OPENROUTER && preg_match('#^[a-z0-9._-]+/[a-z0-9._:-]+$#i', $ref->model) !== 1) {
            throw ConfigException::invalid($name, 'an OpenRouter model id like author/slug, or provider:id such as openai:gpt-6-luna');
        }
        if (!in_array($ref->provider, $providers, true)) {
            throw ConfigException::invalid($name, sprintf('a model of a provider with a key (%s), not %s', implode(', ', $providers), $ref->provider));
        }
        return $model;
    }

    /**
     * OpenRouter's defaults stay bare ids, as they were stored before providers had prefixes.
     *
     * @param non-empty-string $model
     * @return non-empty-string
     */
    private static function bare(string $model): string {
        $prefix = ModelRef::OPENROUTER . ':';
        $id = str_starts_with($model, $prefix) ? substr($model, strlen($prefix)) : $model;
        return $id === '' ? $model : $id;
    }

    /** @return 'none'|'minimal'|'low'|'medium'|'high'|'xhigh'|'max' */
    private static function effort(string $value): string {
        return match ($value) {
            'none' => 'none',
            'minimal' => 'minimal',
            'low' => 'low',
            'medium' => 'medium',
            'high' => 'high',
            'xhigh' => 'xhigh',
            default => 'max',
        };
    }

    /** The key of a provider, null when it has none. */
    public function key(string $provider): ?string {
        return $this->keys[$provider] ?? null;
    }

    public function openRouterKey(): ?string {
        return $this->key(ModelRef::OPENROUTER);
    }

    public function openAiKey(): ?string {
        return $this->key(ModelRef::OPENAI);
    }

    public function anthropicKey(): ?string {
        return $this->key(ModelRef::ANTHROPIC);
    }

    /** Bedrock API key (a bearer token), AWS_BEARER_TOKEN_BEDROCK. */
    public function bedrockKey(): ?string {
        return $this->key(ModelRef::BEDROCK);
    }

    public function geminiKey(): ?string {
        return $this->key(ModelRef::GEMINI);
    }

    /** @return list<string> providers with a key, in the order of the profiles: OpenRouter first */
    public function providers(): array {
        return array_keys($this->keys);
    }

    /** Outbound route for model requests; null means direct. */
    public function routeUri(): ?string {
        return $this->routeUri;
    }

    /** @return array<string, int|string|null> */
    public function __debugInfo(): array {
        return [
            'keys' => implode(', ', array_keys($this->keys)),
            'bedrockRegion' => $this->bedrockRegion,
            'vertexProject' => $this->vertexProject,
            'vertexRegion' => $this->vertexRegion,
            'route' => $this->routeUri === null ? 'direct' : 'vless',
            'model' => $this->model,
            'reasoningEffort' => $this->reasoningEffort,
            'maxTokens' => $this->maxTokens,
            'historyLimit' => $this->historyLimit,
            'contextWindow' => $this->contextWindow,
            'cheapModel' => $this->cheapModel,
        ];
    }
}
