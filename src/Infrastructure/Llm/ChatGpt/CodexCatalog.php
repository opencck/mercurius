<?php

declare(strict_types=1);

namespace OpenCCK\Infrastructure\Llm\ChatGpt;

use Amp\Http\Client\HttpClient;
use Amp\Http\Client\Request;
use Mercurius\Llm\ModelCatalog\ModelInfo;
use Throwable;

/**
 * The models the owner's subscription offers, from the Codex catalog of the account: visible ones, by their priority.
 * They cost nothing per token (the subscription pays). Not signed in, or the catalog down — no models.
 *
 * spec: brief/models.md#провайдеры-моделей-openrouter-и-openai
 * ref: references/hermes-agent/agent/model_metadata.py@e96221fa:L1759-L1810 (catalog URLs, client_version 99.0.0 then
 *      0.0.0) and hermes_cli/codex_models.py@e96221fa:L136-L160 (_ranked_slugs: hidden ones out, by priority)
 */
final class CodexCatalog {
    public function __construct(
        private readonly HttpClient $http,
        private readonly CodexAccount $account,
    ) {}

    /** @return list<ModelInfo> */
    public function fetch(): array {
        if (!$this->account->signedIn()) {
            return [];
        }
        try {
            $token = $this->account->accessToken();
            foreach (['99.0.0', '0.0.0'] as $version) {
                $request = new Request(CodexClient::BASE_URL . '/models?client_version=' . $version);
                $request->setHeader('authorization', 'Bearer ' . $token);
                foreach (CodexAccount::accountHeaders($token) as $name => $value) {
                    $request->setHeader($name, $value);
                }
                $response = $this->http->request($request);
                $body = $response->getBody()->buffer();
                if ($response->getStatus() !== 200) {
                    continue;
                }
                $models = self::models($body);
                if ($models !== []) {
                    return $models;
                }
            }
        } catch (Throwable) {
        }
        return [];
    }

    /** @return list<ModelInfo> */
    public static function models(string $body): array {
        try {
            /** @var mixed $data */
            $data = json_decode($body, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }
        /** @var mixed $entries */
        $entries = is_array($data) ? ($data['models'] ?? null) : null;
        $ranked = [];
        foreach (is_array($entries) ? $entries : [] as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $slug = trim(self::text($entry, 'slug'));
            $visibility = strtolower(trim(self::text($entry, 'visibility')));
            if ($slug === '' || $visibility === 'hide' || $visibility === 'hidden') {
                continue;
            }
            /** @var mixed $priority */
            $priority = $entry['priority'] ?? null;
            /** @var mixed $context */
            $context = $entry['context_window'] ?? null;
            $name = self::text($entry, 'display_name') ?: $slug;
            $ranked[] = [is_int($priority) || is_float($priority) ? (int) $priority : 10_000, $slug, new ModelInfo($slug, $name, is_int($context) ? $context : null, 0.0, 0.0, ['text', 'image'], ['text'], ['tools', 'reasoning'])];
        }
        usort($ranked, static fn(array $a, array $b): int => [$a[0], $a[1]] <=> [$b[0], $b[1]]);
        $seen = [];
        $models = [];
        foreach ($ranked as [, $slug, $info]) {
            if (!isset($seen[$slug])) {
                $seen[$slug] = true;
                $models[] = $info;
            }
        }
        return $models;
    }

    /** @param array<array-key, mixed> $data */
    private static function text(array $data, string $key): string {
        /** @var mixed $value */
        $value = $data[$key] ?? null;
        return is_string($value) ? $value : '';
    }
}
