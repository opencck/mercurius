<?php

declare(strict_types=1);

namespace OpenCCK\Test\Support;

use DateTimeImmutable;
use OpenCCK\Domain\Agent\EpisodeMessage;
use OpenCCK\Domain\Agent\HistoryScope;
use OpenCCK\Domain\Agent\SearchWords;
use OpenCCK\Domain\Repository\EpisodeRepository;

/** Words match whole and case-insensitively; PostgreSQL also matches word forms. */
final class InMemoryEpisodeRepository implements EpisodeRepository {
    public const MEMORY_BLOCK = "\n\n<untrusted-data source=\"memory\">";

    /** @var array<int, array{string, string}> conversation => [channel, chat] */
    public array $conversations = [];
    /** @var array<int, EpisodeMessage> */
    public array $messages = [];

    public function addConversation(string $channel, string $chatId): int {
        $id = count($this->conversations) + 1;
        $this->conversations[$id] = [$channel, $chatId];
        return $id;
    }

    public function addMessage(int $conversationId, string $role, string $content): int {
        [$channel, $chatId] = $this->conversations[$conversationId] ?? ['test', '1'];
        $id = count($this->messages) + 1;
        $this->messages[$id] = new EpisodeMessage($id, $conversationId, $role, $content, new DateTimeImmutable(), $channel, $chatId);
        return $id;
    }

    public function search(array $words, HistoryScope $scope, int $limit, int $perConversation): array {
        $terms = SearchWords::terms($words);
        if ($terms === [] || $limit < 1) {
            return [];
        }
        $ranked = [];
        foreach ($this->visible($scope) as $message) {
            $text = explode(self::MEMORY_BLOCK, $message->content, 2)[0];
            $tokens = SearchWords::split(mb_strtolower($text));
            $rank = count(array_intersect($terms, $tokens));
            if ($rank > 0) {
                $ranked[] = [$rank, $message];
            }
        }
        usort(
            $ranked,
            /**
             * @param array{int, EpisodeMessage} $a
             * @param array{int, EpisodeMessage} $b
             */
            static fn(array $a, array $b): int => [$b[0], $b[1]->id] <=> [$a[0], $a[1]->id],
        );
        $found = [];
        $taken = [];
        foreach ($ranked as [, $message]) {
            $taken[$message->conversationId] = ($taken[$message->conversationId] ?? 0) + 1;
            if ($taken[$message->conversationId] <= $perConversation && count($found) < $limit) {
                $found[] = $message;
            }
        }
        return $found;
    }

    public function around(int $conversationId, int $messageId, HistoryScope $scope, int $window): array {
        $thread = array_values(array_filter($this->visible($scope), static fn(EpisodeMessage $m): bool => $m->conversationId === $conversationId));
        foreach ($thread as $i => $message) {
            if ($message->id === $messageId) {
                return array_slice($thread, max(0, $i - $window), min($i, $window) + 1 + $window);
            }
        }
        return [];
    }

    /** @return list<EpisodeMessage> */
    private function visible(HistoryScope $scope): array {
        return array_values(array_filter(
            $this->messages,
            static fn(EpisodeMessage $m): bool => in_array($m->role, ['user', 'assistant'], true) && $m->channel !== 'worker' && $scope->allows($m->channel, $m->chatId),
        ));
    }
}
