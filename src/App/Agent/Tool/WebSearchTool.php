<?php

declare(strict_types=1);

namespace OpenCCK\App\Agent\Tool;

use Mercurius\Llm\ToolDefinition;
use Mercurius\Search\SearchException;
use Mercurius\Search\SearchProvider;
use OpenCCK\Domain\Agent\DangerClass;

/**
 * Searching the web through the providers the owner chose (SEARCH_PROVIDERS), in order, with a short cache. The
 * answer and the pages are untrusted data; to check a fact, open the page with web_read.
 *
 * spec: BRIEF.md#решения-владельца-2026-11-17 — search through an interface with pluggable providers
 */
final class WebSearchTool implements Tool {
    public const NAME = 'web_search';

    public function __construct(private readonly SearchProvider $search) {}

    public function definition(): ToolDefinition {
        return new ToolDefinition(self::NAME, 'Ищет в интернете: возвращает список страниц со ссылками и, если поиск его пишет, краткий ответ. '
            . 'Ответ поиска может ошибаться — важное проверяй, открыв источник через web_read. Не повторяй одинаковые запросы.', [
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

    public function execute(array $args, ToolContext $context): ToolResult {
        $limit = max(1, min(10, Args::int($args, 'results') ?? 5));
        try {
            return ToolResult::ok($this->search->search(Args::string($args, 'query') ?? '', $limit, $context->cancellation)->toText());
        } catch (SearchException $e) {
            return ToolResult::error($e->getMessage());
        }
    }
}
