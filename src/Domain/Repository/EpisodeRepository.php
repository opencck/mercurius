<?php

declare(strict_types=1);

namespace OpenCCK\Domain\Repository;

use OpenCCK\Domain\Agent\EpisodeMessage;
use OpenCCK\Domain\Agent\HistoryScope;

/**
 * Past conversations as episodes: the messages themselves, searched by words. Workers' conversations and tool
 * results are not searched.
 *
 * spec: BRIEF.md#память-слои — layer 6
 */
interface EpisodeRepository {
    /**
     * Messages matching any of the words, best first, at most $perConversation from one conversation.
     *
     * @param list<string> $words
     * @return list<EpisodeMessage>
     */
    public function search(array $words, HistoryScope $scope, int $limit, int $perConversation): array;

    /**
     * The message and up to $window messages before and after it in its conversation; [] when the reader may not see it.
     *
     * @return list<EpisodeMessage>
     */
    public function around(int $conversationId, int $messageId, HistoryScope $scope, int $window): array;
}
