<?php

declare(strict_types=1);

namespace OpenCCK\Infrastructure\Media;

use Amp\CancelledException;
use Amp\Http\Client\BufferedContent;
use Amp\Http\Client\Form;
use Amp\Http\Client\HttpClient;
use Amp\Http\Client\Request;
use Closure;
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
 * spec: BRIEF.md#входящие-вложения — models, calls, spend
 * ref: references/hermes-agent/tools/vision_tools.py@e96221fa:L348-L356 (_image_to_base64_data_url) — an image goes as
 *      a data: URL to an auxiliary vision model; here to the first model of the owner's list that answers
 */
final class MediaModels {
    public const OPENROUTER = 'https://openrouter.ai/api/v1';
    public const OPENAI = 'https://api.openai.com/v1';
    private const MAX_TOKENS = 800;
    private const RESPONSE_MAX = 1_048_576;

    /** @param Closure(string): ?string $endpointKey the key of an own endpoint by its name; null — none */
    public function __construct(
        private readonly HttpClient $http,
        private readonly ?string $openRouterKey,
        private readonly ?string $openAiKey,
        private readonly MediaSettings $settings,
        private readonly Closure $endpointKey,
        private readonly LlmCallRepository $calls,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {}

    /** @param list<array{bytes: string, mime: string}> $images */
    public function look(string $ref, array $images, string $prompt, MediaCall $call): MediaAnswer {
        $parts = [['type' => 'text', 'text' => $prompt]];
        foreach ($images as $image) {
            $parts[] = ['type' => 'image_url', 'image_url' => ['url' => 'data:' . $image['mime'] . ';base64,' . base64_encode($image['bytes'])]];
        }
        return $this->chat($ref, $parts, 'media-vision', $call);
    }

    public function video(string $ref, string $bytes, string $mime, string $prompt, MediaCall $call): MediaAnswer {
        return $this->chat($ref, [
            ['type' => 'text', 'text' => $prompt],
            ['type' => 'video_url', 'video_url' => ['url' => 'data:' . $mime . ';base64,' . base64_encode($bytes)]],
        ], 'media-video', $call);
    }

    public function transcribe(string $ref, string $bytes, string $fileName, MediaCall $call): MediaAnswer {
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
     * @param list<array<string, mixed>> $parts
     * @param non-empty-string $purpose
     */
    private function chat(string $ref, array $parts, string $purpose, MediaCall $call): MediaAnswer {
        $target = $this->target($ref);
        if (is_string($target)) {
            return new MediaAnswer(null, $target, $ref);
        }
        [$base, $model, $key] = $target;
        $body = ['model' => $model, 'messages' => [['role' => 'user', 'content' => $parts]]];
        // OpenAI takes max_completion_tokens; OpenRouter reports the cost when asked
        $body += str_starts_with($ref, 'openai:') ? ['max_completion_tokens' => self::MAX_TOKENS] : ['max_tokens' => self::MAX_TOKENS];
        if ($base === self::OPENROUTER) {
            $body['usage'] = ['include' => true];
        }
        $request = new Request($base . '/chat/completions', 'POST', json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $request->setHeader('content-type', 'application/json');
        return $this->send($request, $key, $ref, $purpose, $call, static function (array $data): ?string {
            /** @var mixed $content */
            $content = self::dig($data, 'choices', 0, 'message', 'content');
            if (!is_array($content)) {
                return is_string($content) ? $content : null;
            }
            $text = '';
            /** @var mixed $part */
            foreach ($content as $part) {
                /** @var mixed $piece */
                $piece = is_array($part) ? ($part['text'] ?? null) : null;
                $text .= is_string($piece) ? $piece : '';
            }
            return $text;
        });
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
            $response = $this->http->request($request, $call->cancellation);
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
        } catch (Throwable $e) {
            $this->logger->warning('Media call was not recorded', ['error' => $e::class]);
        }
    }
}
