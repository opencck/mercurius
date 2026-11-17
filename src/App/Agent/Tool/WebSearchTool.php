<?php

declare(strict_types=1);

namespace OpenCCK\App\Agent\Tool;

use Amp\Http\Client\HttpClient;
use Amp\Http\Client\Request;
use Mercurius\Llm\ToolDefinition;
use OpenCCK\Domain\Agent\DangerClass;
use SensitiveParameter;
use Throwable;

/**
 * Searching the web through the search of a model on OpenRouter (the :online variant): an answer with the pages it is
 * built on. The answer and the pages are untrusted data; to check a fact, open the page with web_read.
 *
 * spec: BRIEF.md#интернет-и-браузер — search by the model's web search until the owner chooses a search backend
 */
final class WebSearchTool implements Tool {
    public const NAME = 'web_search';
    private const URL = 'https://openrouter.ai/api/v1/chat/completions';

    private readonly string $key;

    public function __construct(
        private readonly HttpClient $http,
        #[SensitiveParameter] string $key,
        /** an OpenRouter model id; ":online" is added */
        private readonly string $model,
    ) {
        $this->key = $key;
    }

    public function definition(): ToolDefinition {
        return new ToolDefinition(self::NAME, 'Ищет в интернете: возвращает краткий ответ и список источников со ссылками. Ответ поиска может '
            . 'ошибаться — важное проверяй, открыв источник через web_read. Поиск платный: не повторяй одинаковые запросы.', [
            'type' => 'object',
            'properties' => [
                'query' => ['type' => 'string', 'minLength' => 2, 'maxLength' => 500],
                'results' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 10],
            ],
            'required' => ['query'],
            'additionalProperties' => false,
        ]);
    }

    public function danger(array $args): DangerClass {
        return DangerClass::Read;
    }

    public function untrusted(): bool {
        return true;
    }

    public function timeout(): int {
        return 90;
    }

    /** @return array<array-key, mixed>|null the first choice's message */
    private static function message(mixed $data): ?array {
        if (!is_array($data) || !isset($data['choices']) || !is_array($data['choices'])) {
            return null;
        }
        $choice = $data['choices'][0] ?? null;
        if (!is_array($choice) || !isset($choice['message']) || !is_array($choice['message'])) {
            return null;
        }
        return $choice['message'];
    }

    public function execute(array $args, ToolContext $context): ToolResult {
        $body = [
            'model' => $this->model . ':online',
            'messages' => [
                ['role' => 'system', 'content' => 'Ты помощник поиска. Ответь на запрос кратко и по существу по найденным страницам, со ссылками на источники. Если ответа нет — так и скажи.'],
                ['role' => 'user', 'content' => Args::string($args, 'query') ?? ''],
            ],
            'plugins' => [['id' => 'web', 'max_results' => Args::int($args, 'results') ?? 5]],
            'max_tokens' => 1500,
        ];
        $request = new Request(self::URL, 'POST', json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        $request->setHeader('authorization', 'Bearer ' . $this->key);
        $request->setHeader('content-type', 'application/json');
        $request->setTransferTimeout(80);
        $request->setInactivityTimeout(80);
        try {
            $response = $this->http->request($request, $context->cancellation);
            $text = $response->getBody()->buffer($context->cancellation);
        } catch (Throwable $e) {
            return ToolResult::error('Поиск не ответил: ' . (new \ReflectionClass($e))->getShortName());
        }
        /** @var mixed $data */
        $data = json_decode($text, true, 32);
        $message = self::message($data);
        if ($response->getStatus() !== 200 || $message === null) {
            return ToolResult::error(sprintf('Поиск не удался (HTTP %d).', $response->getStatus()));
        }
        $answer = isset($message['content']) && is_string($message['content']) ? trim($message['content']) : '';
        $sources = [];
        $annotations = isset($message['annotations']) && is_array($message['annotations']) ? $message['annotations'] : [];
        foreach (array_keys($annotations) as $k) {
            if (isset($annotations[$k]) && is_array($annotations[$k]) && isset($annotations[$k]['url_citation']) && is_array($annotations[$k]['url_citation'])) {
                $citation = $annotations[$k]['url_citation'];
                $url = isset($citation['url']) && is_string($citation['url']) ? $citation['url'] : '';
                $title = isset($citation['title']) && is_string($citation['title']) ? $citation['title'] : '';
                if ($url !== '') {
                    $sources[$url] = sprintf('- %s%s', $title === '' ? '' : $title . ' — ', $url);
                }
            }
        }
        return ToolResult::ok(($answer === '' ? '(пустой ответ)' : $answer) . ($sources === [] ? '' : "\n\nИсточники:\n" . implode("\n", $sources)));
    }
}
