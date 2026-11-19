<?php

declare(strict_types=1);

namespace OpenCCK\Infrastructure\Repository;

use Amp\Postgres\PostgresLink;
use DateTimeImmutable;
use OpenCCK\Domain\Agent\EpisodeMessage;
use OpenCCK\Domain\Agent\HistoryScope;
use OpenCCK\Domain\Agent\SearchWords;
use OpenCCK\Domain\Repository\EpisodeRepository;
use UnexpectedValueException;

/**
 * Full-text search over messages (messages.search, the russian configuration).
 *
 * ref: references/hermes-agent/tools/session_search_tool.py@e96221fa:L1-L33 — search and read shapes, subagent
 *      sessions hidden; we rank single messages and cap hits per conversation instead of deduping by lineage
 */
final class PostgresEpisodeRepository implements EpisodeRepository {
    private const COLUMNS = 'm.id, m.conversation_id, m.role, m.content, extract(epoch FROM m.created_at)::bigint AS at, c.channel, c.chat_id';
    /** rows scanned before the per-conversation cap */
    private const SCAN = 200;

    public function __construct(private readonly PostgresLink $db) {}

    public function search(array $words, HistoryScope $scope, int $limit, int $perConversation, int $except = 0): array {
        $query = self::tsquery($words);
        if ($query === '' || $limit < 1) {
            return [];
        }
        [$where, $params] = self::scope($scope);
        $found = [];
        $taken = [];
        foreach ($this->db->execute(
            'SELECT ' . self::COLUMNS . ", ts_rank_cd(m.search, q) AS rank
             FROM messages m JOIN conversations c ON c.id = m.conversation_id, to_tsquery('russian'::regconfig, ?) q
             WHERE m.search @@ q AND c.channel NOT IN ('worker', 'eval') AND m.conversation_id <> ?" . $where . '
             ORDER BY rank DESC, m.id DESC LIMIT ' . self::SCAN,
            [$query, $except, ...$params],
        ) as $row) {
            $message = self::message($row);
            $taken[$message->conversationId] = ($taken[$message->conversationId] ?? 0) + 1;
            if ($taken[$message->conversationId] > $perConversation) {
                continue;
            }
            $found[] = $message;
            if (count($found) >= $limit) {
                break;
            }
        }
        return $found;
    }

    public function around(int $conversationId, int $messageId, HistoryScope $scope, int $window): array {
        [$where, $params] = self::scope($scope);
        $base = 'SELECT ' . self::COLUMNS . " FROM messages m JOIN conversations c ON c.id = m.conversation_id
                 WHERE m.conversation_id = ? AND m.role IN ('user', 'assistant') AND c.channel NOT IN ('worker', 'eval')" . $where;
        $anchor = $this->db->execute($base . ' AND m.id = ?', [$conversationId, ...$params, $messageId])->fetchRow();
        if ($anchor === null) {
            return [];
        }
        $before = [];
        foreach ($this->db->execute($base . ' AND m.id < ? ORDER BY m.id DESC LIMIT ?', [$conversationId, ...$params, $messageId, $window]) as $row) {
            $before[] = self::message($row);
        }
        $after = [];
        foreach ($this->db->execute($base . ' AND m.id > ? ORDER BY m.id LIMIT ?', [$conversationId, ...$params, $messageId, $window]) as $row) {
            $after[] = self::message($row);
        }
        return [...array_reverse($before), self::message($anchor), ...$after];
    }

    /**
     * Words joined by OR: a message with more of them ranks higher. Only letters and digits reach to_tsquery, so a
     * query cannot break its syntax.
     *
     * @param list<string> $words
     */
    public static function tsquery(array $words): string {
        return implode(' | ', SearchWords::terms($words));
    }

    /** @return array{string, list<string>} the condition on conversations c and its parameters */
    public static function scope(HistoryScope $scope): array {
        if ($scope->isAll()) {
            // spec: BRIEF.md#несколько-пользователей — the private chats of a former owner stay theirs
            return $scope->hidden === [] ? ['', []] : [' AND c.principal_id <> ALL(?::bigint[])', ['{' . implode(',', $scope->hidden) . '}']];
        }
        return [' AND c.channel = ? AND c.chat_id = ?', [(string) $scope->channel, (string) $scope->chatId]];
    }

    /** @param array<string, mixed> $row */
    private static function message(array $row): EpisodeMessage {
        return new EpisodeMessage(
            self::int($row, 'id'),
            self::int($row, 'conversation_id'),
            self::str($row, 'role'),
            self::str($row, 'content'),
            new DateTimeImmutable('@' . self::int($row, 'at')),
            self::str($row, 'channel'),
            self::str($row, 'chat_id'),
        );
    }

    /** @param array<string, mixed> $row */
    private static function int(array $row, string $key): int {
        $value = $row[$key] ?? null;
        if (!is_int($value)) {
            throw new UnexpectedValueException(sprintf('Malformed episode row: %s', $key));
        }
        return $value;
    }

    /** @param array<string, mixed> $row */
    private static function str(array $row, string $key): string {
        $value = $row[$key] ?? null;
        if (!is_string($value)) {
            throw new UnexpectedValueException(sprintf('Malformed episode row: %s', $key));
        }
        return $value;
    }
}
