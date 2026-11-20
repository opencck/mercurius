<?php

declare(strict_types=1);

namespace OpenCCK\App\Agent\Tool\Memory;

use DateTimeZone;
use Mercurius\Llm\ToolDefinition;
use OpenCCK\App\Agent\ConversationAudience;
use OpenCCK\App\Agent\Tool\Args;
use OpenCCK\App\Agent\Tool\Guard\SecretMasker;
use OpenCCK\App\Agent\Tool\Tool;
use OpenCCK\App\Agent\Tool\ToolContext;
use OpenCCK\App\Agent\Tool\ToolResult;
use OpenCCK\Domain\Agent\DangerClass;
use OpenCCK\Domain\Agent\EpisodeMessage;
use OpenCCK\Domain\Agent\SearchWords;
use OpenCCK\Domain\Repository\EpisodeRepository;

use function Mercurius\I18n\t;

/**
 * Searches past conversations and reads around a found message, within what this conversation's audience may see.
 * The messages come back as they were, without a model in between.
 *
 * spec: brief/memory.md#память-слои — layer 6, only by a search tool, never automatically
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
        return new ToolDefinition(self::NAME, t('tool.history_search.ischet_v_proshlyh_razgovorah_esli')
            . t('tool.history_search.reshili_chto_ya_govoril_pro')
            . t('tool.history_search.dlya_poiska_naydennoe_prihodit_otryvkami')
            . t('tool.history_search.soobscheniya_vokrug_naydennogo_tekuschay')
            . t('tool.history_search.smotri_ego_v_istorii_ne'), [
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
            return ToolResult::error(t('tool.history_search.beseda_ne_naydena'));
        }
        $conversation = Args::int($args, 'conversation_id');
        $message = Args::int($args, 'message_id');
        if ($conversation !== null && $message !== null) {
            $window = $this->episodes->around($conversation, $message, $scope, self::WINDOW);
            if ($window === []) {
                return ToolResult::error(sprintf(t('tool.history_search.soobschenie_besedy_ne_naydeno_ili'), $message, $conversation));
            }
            return ToolResult::ok(implode("\n\n", array_map(
                static fn(EpisodeMessage $m): string => self::head($m, $scope->isAll()) . "\n" . self::cut(self::clean($m->content), [], self::READ_CHARS),
                $window,
            )));
        }
        $query = Args::string($args, 'query') ?? '';
        if (trim($query) === '') {
            return ToolResult::error(t('tool.history_search.nuzhen_query_ili_conversation_id'));
        }
        $found = $this->episodes->search([$query], $scope, self::LIMIT, self::PER_CONVERSATION, $context->conversationId);
        if ($found === []) {
            return ToolResult::ok(t('tool.history_search.v_istorii_nichego_ne_nashlos'));
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
            t('tool.history_search.beseda_soobschenie'),
            $m->conversationId,
            $m->id,
            $at,
            $m->role === 'assistant' ? t('tool.history_search.agent') : t('tool.history_search.polzovatel'),
            $withChat ? sprintf(t('tool.history_search.chat'), $m->channel, $m->chatId) : '',
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
