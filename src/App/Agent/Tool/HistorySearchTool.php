<?php

declare(strict_types=1);

namespace OpenCCK\App\Agent\Tool;

use DateTimeZone;
use Mercurius\Llm\ToolDefinition;
use OpenCCK\App\Agent\ConversationAudience;
use OpenCCK\Domain\Agent\DangerClass;
use OpenCCK\Domain\Agent\EpisodeMessage;
use OpenCCK\Domain\Agent\SearchWords;
use OpenCCK\Domain\Repository\EpisodeRepository;

/**
 * Searches past conversations and reads around a found message, within what this conversation's audience may see.
 * The messages come back as they were, without a model in between.
 *
 * spec: BRIEF.md#память-слои — layer 6, only by a search tool, never automatically
 * ref: references/hermes-agent/tools/session_search_tool.py@e96221fa:L816-L832 (SESSION_SEARCH_SCHEMA) — search and
 *      scroll shapes, "search before asking the user to repeat"; no browse and no whole-session read: the window is enough
 */
final class HistorySearchTool implements Tool {
    public const NAME = 'history_search';
    public const LIMIT = 8;
    public const PER_CONVERSATION = 2;
    public const WINDOW = 4;
    public const EXCERPT = 400;
    public const READ_CHARS = 1500;
    private const MEMORY_BLOCK = "\n\n<untrusted-data source=\"memory\">";

    public function __construct(
        private readonly EpisodeRepository $episodes,
        private readonly ConversationAudience $audience,
    ) {}

    public function definition(): ToolDefinition {
        return new ToolDefinition(self::NAME, 'Ищет в прошлых разговорах. Если пользователь ссылается на прошлый разговор («как мы '
            . 'решили…», «что я говорил про…», «на чём остановились») — сначала поищи здесь, не проси повторить. query — слова '
            . 'для поиска; найденное приходит отрывками с номерами беседы и сообщения. conversation_id и message_id — прочитать '
            . 'сообщения вокруг найденного. Это только история переписки: если есть прямой источник (сервер, файл, сайт), '
            . 'смотри его; «в истории не нашлось» не значит «не было».', [
            'type' => 'object',
            'properties' => [
                'query' => ['type' => 'string', 'minLength' => 2, 'maxLength' => 300],
                'conversation_id' => ['type' => 'integer', 'minimum' => 1],
                'message_id' => ['type' => 'integer', 'minimum' => 1],
            ],
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
        return 15;
    }

    public function execute(array $args, ToolContext $context): ToolResult {
        $scope = $this->audience->history($context->principal, $context->conversationId);
        if ($scope === null) {
            return ToolResult::error('Беседа не найдена.');
        }
        $conversation = Args::int($args, 'conversation_id');
        $message = Args::int($args, 'message_id');
        if ($conversation !== null && $message !== null) {
            $window = $this->episodes->around($conversation, $message, $scope, self::WINDOW);
            if ($window === []) {
                return ToolResult::error(sprintf('Сообщение #%d беседы #%d не найдено или недоступно отсюда.', $message, $conversation));
            }
            return ToolResult::ok(implode("\n\n", array_map(
                static fn(EpisodeMessage $m): string => self::head($m, $scope->isAll()) . "\n" . self::cut(self::clean($m->content), [], self::READ_CHARS),
                $window,
            )));
        }
        $query = Args::string($args, 'query') ?? '';
        if (trim($query) === '') {
            return ToolResult::error('Нужен query или conversation_id вместе с message_id.');
        }
        $found = $this->episodes->search([$query], $scope, self::LIMIT, self::PER_CONVERSATION);
        if ($found === []) {
            return ToolResult::ok('В истории ничего не нашлось.');
        }
        $words = SearchWords::terms([$query]);
        return ToolResult::ok(implode("\n\n", array_map(
            static fn(EpisodeMessage $m): string => self::head($m, $scope->isAll()) . "\n" . self::cut(self::clean($m->content), $words, self::EXCERPT),
            $found,
        )));
    }

    private static function head(EpisodeMessage $m, bool $withChat): string {
        $at = $m->createdAt->setTimezone(new DateTimeZone(date_default_timezone_get()))->format('Y-m-d H:i');
        return sprintf(
            '[беседа #%d, сообщение #%d, %s, %s%s]',
            $m->conversationId,
            $m->id,
            $at,
            $m->role === 'assistant' ? 'агент' : 'пользователь',
            $withChat ? sprintf(', чат %s:%s', $m->channel, $m->chatId) : '',
        );
    }

    /** The text without the recalled memory block, secrets masked. */
    private static function clean(string $content): string {
        return SecretMasker::mask(trim(explode(self::MEMORY_BLOCK, $content, 2)[0]));
    }

    /**
     * Up to $max characters around the first word of the query found in the text (by its first five letters, so a
     * different word form is found too).
     *
     * @param list<string> $words
     */
    public static function cut(string $text, array $words, int $max): string {
        $length = mb_strlen($text);
        if ($length <= $max) {
            return $text;
        }
        $at = null;
        foreach ($words as $word) {
            if (mb_strlen($word) < 2) {
                continue;
            }
            $found = mb_stripos($text, mb_substr($word, 0, 5));
            if ($found !== false && ($at === null || $found < $at)) {
                $at = $found;
            }
        }
        $start = $at === null ? 0 : max(0, min($at - intdiv($max, 3), $length - $max));
        return ($start > 0 ? '…' : '') . mb_substr($text, $start, $max) . ($start + $max < $length ? '…' : '');
    }
}
