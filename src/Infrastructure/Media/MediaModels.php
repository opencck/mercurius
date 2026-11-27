<?php

declare(strict_types=1);

namespace OpenCCK\Infrastructure\Media;

use Amp\CancelledException;
use Amp\Http\Client\BufferedContent;
use Amp\Http\Client\Form;
use Amp\Http\Client\HttpClient;
use Amp\Http\Client\Request;
use Closure;
use Mercurius\Llm\ChatRequest;
use Mercurius\Llm\ImageRequest;
use Mercurius\Llm\LlmClient;
use Mercurius\Llm\LlmException;
use Mercurius\Llm\MediaPart;
use Mercurius\Llm\Message;
use Mercurius\Llm\OpenAI\OpenAIClient;
use Mercurius\Llm\OpenRouter\ImageClient;
use Mercurius\Llm\OpenRouter\OpenRouterClient;
use Mercurius\Llm\RetryingClient;
use Mercurius\Llm\Usage;
use OpenCCK\Domain\Agent\ModelRef;
use OpenCCK\Domain\Entity\LlmCallRecord;
use OpenCCK\Domain\Repository\LlmCallRepository;
use OpenCCK\Infrastructure\Config\MediaSettings;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

use function Mercurius\I18n\t;

/**
 * Vision, speech and video models over OpenAI-compatible APIs: images and video as parts of a chat message
 * (`image_url`, `video_url` with data: URLs), speech as a file upload to /audio/transcriptions. The provider comes
 * from the reference: openrouter: (or a bare author/slug), openai:, custom:<endpoint>/<model> — an address of the
 * owner's own (MEDIA_ENDPOINTS, its key a vault secret). The models get only the file and the question, never the
 * conversation; every call is recorded like a text model's.
 *
 * spec: brief/internet.md#модели-вложений — models, calls, spend
 * ref: references/hermes-agent/tools/vision_tools.py@e96221fa:L348-L356 (_image_to_base64_data_url) — an image goes as
 *      a data: URL to an auxiliary vision model; here to the first model of the owner's list that answers
 */
final class MediaModels {
    public const OPENROUTER = 'https://openrouter.ai/api/v1';
    public const OPENAI = 'https://api.openai.com/v1';
    private const MAX_TOKENS = 800;
    private const RESPONSE_MAX = 1_048_576;

    /**
     * @param Closure(string): ?string $endpointKey the key of an own endpoint by its name; null — none
     * @param array<string, LlmClient> $clients the text models' clients of the other providers (anthropic, bedrock,
     *                                          codex): a picture goes to such a model through its provider's client
     * @param HttpClient|null $localHttp a direct client for an own endpoint inside the host's network (a compose
     *                                   service such as whisper, localhost, a private address): the models'
     *                                   route (VLESS) does not reach it; null — the models' client for every address
     */
    public function __construct(
        private readonly HttpClient $http,
        private readonly ?string $openRouterKey,
        private readonly ?string $openAiKey,
        private readonly MediaSettings $settings,
        private readonly Closure $endpointKey,
        private readonly LlmCallRepository $calls,
        private readonly LoggerInterface $logger = new NullLogger(),
        private readonly array $clients = [],
        private readonly ?HttpClient $localHttp = null,
    ) {}

    private ?\OpenCCK\Domain\Repository\RunRepository $runs = null;

    /**
     * The runs whose counters take the tokens of a media call made inside a run, as a search made by a run does.
     *
     * spec: brief/operations.md#расход-что-знает-агент — the tokens of a run are all the tokens its calls spent
     */
    public function useRuns(\OpenCCK\Domain\Repository\RunRepository $runs): void {
        $this->runs = $runs;
    }

    private ?McpMedia $mcp = null;

    /**
     * The models of the providers MCP servers offer (`mcp-<entry>:<model>`) stand in the categories of media too.
     *
     * spec: brief/skills-mcp.md#провайдеры-моделей-от-mcp
     */
    public function useMcp(McpMedia $mcp): void {
        $this->mcp = $mcp;
    }

    /**
     * A model of a provider of an MCP: its answer as text and files, the call kept in the spend like any other.
     *
     * @param non-empty-string $purpose
     * @param array<string, mixed> $args
     * @return array{?string, list<array{name: string, type: string, bytes: string}>, ?string} the text, the files, why it failed
     */
    private function viaMcp(string $ref, string $kind, string $purpose, array $args, MediaCall $call): array {
        $started = microtime(true);
        if ($this->mcp === null) {
            return [null, [], sprintf(t('model.lists.mcp_unknown'), $ref, '—')];
        }
        try {
            $answer = $this->mcp->call($ref, $kind, $args, $call->cancellation);
        } catch (\DomainException $e) {
            $this->record($ref, $purpose, [], $e->getMessage(), $call, (int) round((microtime(true) - $started) * 1000.0));
            $this->logger->notice('A model of an MCP did not answer', ['model' => $ref, 'error' => mb_substr($e->getMessage(), 0, 200)]);
            return [null, [], $e->getMessage()];
        }
        $this->record($ref, $purpose, ['usage' => ['cost' => $answer['cost']]], null, $call, (int) round((microtime(true) - $started) * 1000.0));
        return [$answer['text'], $answer['files'], null];
    }

    /**
     * Speech from a text by a model of the voice category; only a model of a provider of an MCP makes it.
     *
     * spec: brief/models.md#категории-моделей — generation of sound
     *
     * @return array{?array{name: string, type: string, bytes: string}, ?string} the file, why not
     */
    public function voice(string $ref, string $text, MediaCall $call): array {
        if (!McpMedia::owns($ref)) {
            return [null, sprintf(t('media.mcp.only_mcp'), $ref)];
        }
        [, $files, $error] = $this->viaMcp($ref, 'voice', 'media-voice', ['text' => $text], $call);
        return [$files[0] ?? null, $error ?? ($files === [] ? t('media.mcp.no_file') : null)];
    }

    /**
     * A video from a description by a model of the film category; only a model of a provider of an MCP makes it.
     *
     * @return array{?array{name: string, type: string, bytes: string}, ?string} the file, why not
     */
    public function film(string $ref, string $prompt, ?string $aspectRatio, MediaCall $call): array {
        if (!McpMedia::owns($ref)) {
            return [null, sprintf(t('media.mcp.only_mcp'), $ref)];
        }
        [, $files, $error] = $this->viaMcp($ref, 'film', 'media-film', ['prompt' => $prompt] + ($aspectRatio === null ? [] : ['aspect_ratio' => $aspectRatio]), $call);
        return [$files[0] ?? null, $error ?? ($files === [] ? t('media.mcp.no_file') : null)];
    }

    /**
     * Pictures drawn by an image model of OpenRouter; the cost OpenRouter reports is recorded like any call.
     *
     * spec: brief/models.md#категории-моделей — image generation
     */
    public function draw(string $ref, string $prompt, ?string $aspectRatio, MediaCall $call): MediaDrawing {
        if (McpMedia::owns($ref)) {
            [, $files, $error] = $this->viaMcp($ref, 'image', 'media-image', ['prompt' => $prompt] + ($aspectRatio === null || $aspectRatio === 'auto' ? [] : ['aspect_ratio' => $aspectRatio]), $call);
            $images = [];
            foreach ($files as $file) {
                if (str_starts_with($file['type'], 'image/')) {
                    $images[] = MediaPart::image($file['type'], $file['bytes']);
                }
            }
            return new MediaDrawing($images, $error ?? ($images === [] ? t('media.mcp.no_file') : null), $ref);
        }
        $model = str_starts_with($ref, ModelRef::OPENROUTER . ':') ? substr($ref, strlen(ModelRef::OPENROUTER) + 1) : $ref;
        if ($this->openRouterKey === null) {
            return new MediaDrawing([], sprintf(t('media.models.no_key'), 'OPENROUTER_API_KEY'), $ref);
        }
        $started = microtime(true);
        $images = [];
        $usage = new Usage();
        $error = null;
        try {
            $result = (new ImageClient($this->http, $this->openRouterKey, appName: 'Mercurius'))
                ->generate(new ImageRequest($model, $prompt, 1, $aspectRatio), $call->cancellation);
            $images = $result->images;
            $usage = $result->usage;
        } catch (CancelledException $e) {
            throw $e;
        } catch (LlmException $e) {
            $error = $e->status === null ? $e->getMessage() : sprintf('HTTP %d: %s', $e->status, mb_substr($e->getMessage(), 0, 300));
        } catch (\InvalidArgumentException $e) {
            $error = $e->getMessage();
        } catch (Throwable $e) {
            $error = t('media.models.no_answer') . (new \ReflectionClass($e))->getShortName();
        }
        $this->record($ref, 'media-image', ['usage' => ['prompt_tokens' => $usage->promptTokens(), 'completion_tokens' => $usage->outputTokens, 'cost' => $usage->costUsd]], $error, $call, (int) round((microtime(true) - $started) * 1000.0));
        if ($error !== null) {
            $this->logger->notice('Image model did not draw', ['model' => $ref, 'error' => mb_substr($error, 0, 200)]);
        }
        return new MediaDrawing($images, $error, $ref);
    }

    /** @param list<array{bytes: string, mime: string}> $images */
    public function look(string $ref, array $images, string $prompt, MediaCall $call): MediaAnswer {
        if (McpMedia::owns($ref)) {
            try {
                $files = McpMedia::files(array_map(static fn(int $i, array $image): array => ['name' => sprintf('image-%d.%s', $i + 1, explode('/', $image['mime'])[1] ?? 'bin'), 'mime' => $image['mime'], 'bytes' => $image['bytes']], array_keys($images), $images));
            } catch (\DomainException $e) {
                // too large for the server: an answer, so the next model of the category is tried
                return new MediaAnswer(null, $e->getMessage(), $ref);
            }
            [$text, , $error] = $this->viaMcp($ref, 'vision', 'media-vision', ['question' => $prompt, 'files' => $files], $call);
            return new MediaAnswer($text, $error, $ref);
        }
        return $this->chat($ref, $prompt, array_map(static fn(array $i): MediaPart => MediaPart::image($i['mime'], $i['bytes']), $images), 'media-vision', $call);
    }

    public function video(string $ref, string $bytes, string $mime, string $prompt, MediaCall $call): MediaAnswer {
        if (McpMedia::owns($ref)) {
            try {
                $file = McpMedia::file('video.' . (explode('/', $mime)[1] ?? 'mp4'), $mime, $bytes);
            } catch (\DomainException $e) {
                return new MediaAnswer(null, $e->getMessage(), $ref);
            }
            [$text, , $error] = $this->viaMcp($ref, 'video', 'media-video', ['question' => $prompt, 'files' => [$file]], $call);
            return new MediaAnswer($text, $error, $ref);
        }
        return $this->chat($ref, $prompt, [MediaPart::video($mime, $bytes)], 'media-video', $call);
    }

    public function transcribe(string $ref, string $bytes, string $fileName, MediaCall $call): MediaAnswer {
        if (McpMedia::owns($ref)) {
            try {
                $file = McpMedia::file($fileName, 'application/octet-stream', $bytes);
            } catch (\DomainException $e) {
                return new MediaAnswer(null, $e->getMessage(), $ref);
            }
            [$text, , $error] = $this->viaMcp($ref, 'speech', 'media-speech', ['files' => [$file]], $call);
            return new MediaAnswer($text, $error, $ref);
        }
        $target = $this->target($ref);
        if (is_string($target)) {
            return new MediaAnswer(null, $target, $ref);
        }
        [$base, $model, $key] = $target;
        $form = new Form();
        $form->addField('model', $model);
        $form->addStream('file', BufferedContent::fromString($bytes, 'application/octet-stream'), $fileName);
        $request = new Request($base . '/audio/transcriptions', 'POST');
        $request->setBody($form);
        return $this->send($request, $key, $ref, 'media-speech', $call, static function (array $data): ?string {
            return isset($data['text']) && is_string($data['text']) ? $data['text'] : null;
        });
    }

    /**
     * The first model of the list that answers; when none does, every reason.
     *
     * @param list<string> $refs
     * @param Closure(string): MediaAnswer $call
     */
    public function first(array $refs, Closure $call): MediaAnswer {
        $errors = [];
        foreach ($refs as $ref) {
            $answer = $call($ref);
            if ($answer->text !== null) {
                return $answer;
            }
            $errors[] = $ref . ': ' . ($answer->error ?? t('media.models.empty'));
        }
        return new MediaAnswer(null, $errors === [] ? t('media.models.none') : implode('; ', $errors), $refs[0] ?? '');
    }

    /**
     * A picture or a video with its question, through the same client as the text models: one way of retries,
     * of errors and of spend.
     *
     * spec: brief/internet.md#модели-вложений — models, calls, spend
     *
     * @param list<MediaPart> $media
     * @param non-empty-string $purpose
     */
    private function chat(string $ref, string $prompt, array $media, string $purpose, MediaCall $call): MediaAnswer {
        $own = $this->ownClient($ref);
        if ($own !== null) {
            [$client, $model] = $own;
        } else {
            $target = $this->target($ref);
            if (is_string($target)) {
                return new MediaAnswer(null, $target, $ref);
            }
            [$base, $model, $key] = $target;
            $client = new RetryingClient(match ($base) {
                // OpenAI takes max_completion_tokens; the other endpoints speak the dialect of OpenRouter
                self::OPENAI => new OpenAIClient($this->http, (string) $key, transferTimeout: 180.0),
                default => new OpenRouterClient($this->clientFor($base), $key ?? 'none', $base, transferTimeout: 180.0),
            }, maxAttempts: 2, baseDelay: 0.5);
        }
        $started = microtime(true);
        $text = null;
        $error = null;
        $usage = new Usage();
        try {
            $response = $client->complete(new ChatRequest($model, [Message::user($prompt, $media)], [], self::MAX_TOKENS), $call->cancellation);
            $usage = $response->usage;
            $text = trim($response->text) === '' ? null : trim($response->text);
            $error = $text === null ? t('media.models.empty') : null;
        } catch (CancelledException $e) {
            throw $e;
        } catch (LlmException $e) {
            $error = $e->status === null ? $e->getMessage() : sprintf('HTTP %d: %s', $e->status, mb_substr($e->getMessage(), 0, 300));
        } catch (Throwable $e) {
            $error = t('media.models.no_answer') . (new \ReflectionClass($e))->getShortName();
        }
        $this->record($ref, $purpose, ['usage' => ['prompt_tokens' => $usage->promptTokens(), 'completion_tokens' => $usage->outputTokens, 'cost' => $usage->costUsd]], $error, $call, (int) round((microtime(true) - $started) * 1000.0));
        if ($error !== null) {
            $this->logger->notice('Media model did not answer', ['model' => $ref, 'purpose' => $purpose, 'error' => mb_substr($error, 0, 200)]);
        }
        return new MediaAnswer($text, $error, $ref);
    }

    /**
     * @param non-empty-string $purpose
     * @param Closure(array<array-key, mixed>): ?string $read
     */
    private function send(Request $request, ?string $key, string $ref, string $purpose, MediaCall $call, Closure $read): MediaAnswer {
        if ($key !== null) {
            $request->setHeader('authorization', 'Bearer ' . $key);
        }
        $request->setTransferTimeout(180);
        $request->setInactivityTimeout(120);
        $started = microtime(true);
        $error = null;
        $text = null;
        $data = [];
        try {
            $response = $this->clientFor((string) $request->getUri())->request($request, $call->cancellation);
            $raw = $response->getBody()->buffer($call->cancellation, self::RESPONSE_MAX);
            /** @var mixed $decoded */
            $decoded = json_decode($raw, true, 64);
            $data = is_array($decoded) ? $decoded : [];
            if ($response->getStatus() !== 200) {
                /** @var mixed $message */
                $message = self::dig($data, 'error', 'message') ?? self::dig($data, 'error') ?? mb_substr($raw, 0, 200);
                $error = sprintf('HTTP %d: %s', $response->getStatus(), is_string($message) ? mb_substr($message, 0, 300) : '');
            } else {
                $text = $read($data);
                $text = $text === null || trim($text) === '' ? null : trim($text);
                $error = $text === null ? t('media.models.empty') : null;
            }
        } catch (CancelledException $e) {
            throw $e;
        } catch (Throwable $e) {
            $error = t('media.models.no_answer') . (new \ReflectionClass($e))->getShortName();
        }
        $this->record($ref, $purpose, $data, $error, $call, (int) round((microtime(true) - $started) * 1000.0));
        if ($error !== null) {
            $this->logger->notice('Media model failed', ['model' => $ref, 'purpose' => $purpose, 'error' => mb_substr($error, 0, 200)]);
        }
        return new MediaAnswer($text, $error, $ref);
    }

    /**
     * The client of a provider other than OpenRouter, OpenAI and the owner's endpoints, with the model id; null — the
     * reference is not one of theirs.
     *
     * @return array{LlmClient, string}|null
     */
    private function ownClient(string $ref): ?array {
        if (str_starts_with($ref, 'custom:') || !str_contains($ref, ':')) {
            return null;
        }
        $parsed = ModelRef::parse($ref);
        if (in_array($parsed->provider, [ModelRef::OPENROUTER, ModelRef::OPENAI], true)) {
            return null;
        }
        $client = $this->clients[$parsed->provider] ?? null;
        return $client === null ? null : [$client, $parsed->model];
    }

    /**
     * Where a reference goes: [base URL, model id, key]; a string — why it cannot be called.
     *
     * @return array{string, string, ?string}|string
     */
    private function target(string $ref): array|string {
        if (str_starts_with($ref, 'custom:')) {
            [$name, $model] = explode('/', substr($ref, 7), 2) + [1 => ''];
            $base = $this->settings->endpoints[$name] ?? null;
            return $base === null ? sprintf(t('media.models.no_endpoint'), $name) : [$base, $model, ($this->endpointKey)($name)];
        }
        if (str_starts_with($ref, 'openai:')) {
            return $this->openAiKey === null ? sprintf(t('media.models.no_key'), 'OPENAI_API_KEY') : [self::OPENAI, substr($ref, 7), $this->openAiKey];
        }
        $model = str_starts_with($ref, 'openrouter:') ? substr($ref, 11) : $ref;
        return $this->openRouterKey === null ? sprintf(t('media.models.no_key'), 'OPENROUTER_API_KEY') : [self::OPENROUTER, $model, $this->openRouterKey];
    }

    /**
     * The client for an address: the direct one for a host inside the host's network — a name without a dot (a compose
     * service), localhost, a loopback or private address — and the models' client (its route) for the rest.
     *
     * spec: brief/internet.md#модели-вложений — an own endpoint, such as the whisper module
     */
    private function clientFor(string $url): HttpClient {
        if ($this->localHttp === null) {
            return $this->http;
        }
        $host = strtolower(trim((string) parse_url($url, PHP_URL_HOST), '[]'));
        if ($host === '') {
            return $this->http;
        }
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            $public = filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
            return $public ? $this->http : $this->localHttp;
        }
        return !str_contains($host, '.') || $host === 'localhost' || str_ends_with($host, '.localhost') || str_ends_with($host, '.internal')
            ? $this->localHttp
            : $this->http;
    }

    /** A value deep in decoded JSON; null — not there. */
    private static function dig(mixed $data, string|int ...$path): mixed {
        foreach ($path as $key) {
            if (!is_array($data) || !array_key_exists($key, $data)) {
                return null;
            }
            /** @var mixed $data */
            $data = $data[$key];
        }
        return $data;
    }

    /**
     * @param non-empty-string $purpose
     * @param array<array-key, mixed> $data
     */
    private function record(string $ref, string $purpose, array $data, ?string $error, MediaCall $call, int $durationMs): void {
        /** @var mixed $usage */
        $usage = $data['usage'] ?? [];
        $usage = is_array($usage) ? $usage : [];
        $int = static fn(mixed $v): int => is_int($v) ? max(0, $v) : 0;
        /** @var mixed $cost */
        $cost = $usage['cost'] ?? null;
        try {
            $this->calls->record(new LlmCallRecord(
                $call->conversationId,
                $purpose,
                $ref,
                $error === null ? 'stop' : null,
                $error === null ? null : 'media',
                $int($usage['prompt_tokens'] ?? $usage['input_tokens'] ?? 0),
                $int($usage['completion_tokens'] ?? $usage['output_tokens'] ?? 0),
                0,
                0,
                0,
                is_int($cost) || is_float($cost) ? (float) $cost : null,
                max(0, $durationMs),
                $call->runId,
            ));
            $spent = $int($usage['prompt_tokens'] ?? $usage['input_tokens'] ?? 0) + $int($usage['completion_tokens'] ?? $usage['output_tokens'] ?? 0);
            if ($call->runId !== null && $spent > 0) {
                $this->runs?->settleAuxiliary($call->runId, 0, $spent);
            }
        } catch (Throwable $e) {
            $this->logger->warning('Media call was not recorded', ['error' => $e::class]);
        }
    }
}
