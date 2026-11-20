<?php

declare(strict_types=1);

namespace OpenCCK\Domain\Repository;

use OpenCCK\Domain\Agent\EpisodeMessage;
use OpenCCK\Domain\Agent\HistoryScope;

/**
 * Past conversations as episodes: the messages themselves, searched by words. Workers' conversations and tool
 * results are not searched.
 *
 * spec: brief/memory.md#как-сделано-эпизоды-и-поиск — layer 6
 */
interface EpisodeRepository {
    /**
     * Messages matching any of the words, best first, at most $perConversation from one conversation. The
     * conversation $except is skipped: the reader has it in its context, and its own question would match first.
     *
     * @param list<string> $words
     * @return list<EpisodeMessage>
     */
    public function search(array $words, HistoryScope $scope, int $limit, int $perConversation, int $except = 0): array;

    /**
     * The message and up to $window messages before and after it in its conversation; [] when the reader may not see it.
     *
     * @return list<EpisodeMessage>
     */
    public function around(int $conversationId, int $messageId, HistoryScope $scope, int $window): array;
}
