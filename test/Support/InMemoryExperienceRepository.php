<?php

declare(strict_types=1);

namespace OpenCCK\Test\Support;

use DateTimeImmutable;
use OpenCCK\Domain\Agent\Experience;
use OpenCCK\Domain\Agent\HistoryScope;
use OpenCCK\Domain\Agent\SearchWords;
use OpenCCK\Domain\Repository\ExperienceRepository;

final class InMemoryExperienceRepository implements ExperienceRepository {
    /** @var array<int, array{string, string}> conversation => [channel, chat]; unknown ones are test:1 */
    public array $conversations = [];
    /** @var array<int, Experience> */
    public array $records = [];

    public function add(Experience $experience): int {
        $id = count($this->records) + 1;
        $this->records[$id] = new Experience(
            $experience->conversationId,
            $experience->principalId,
            $experience->runId,
            $experience->goal,
            $experience->role,
            $experience->profile,
            $experience->model,
            $experience->firstModel,
            $experience->status,
            $experience->verdict,
            $experience->reason,
            $experience->turns,
            $experience->tokens,
            $experience->durationMs,
            $id,
            new DateTimeImmutable(),
        );
        return $id;
    }

    public function search(array $words, HistoryScope $scope, int $limit, ?string $model = null): array {
        $terms = SearchWords::terms($words);
        $ranked = [];
        foreach ($this->records as $record) {
            [$channel, $chatId] = $this->conversations[$record->conversationId] ?? ['test', '1'];
            if (!$scope->allows($channel, $chatId) || ($model !== null && $record->model !== $model && $record->firstModel !== $model)) {
                continue;
            }
            $tokens = SearchWords::split(mb_strtolower($record->goal));
            $rank = count(array_intersect($terms, $tokens));
            if ($terms === [] || $rank > 0) {
                $ranked[] = [$rank, $record];
            }
        }
        usort(
            $ranked,
            /**
             * @param array{int, Experience} $a
             * @param array{int, Experience} $b
             */
            static fn(array $a, array $b): int => [$b[0], $b[1]->id] <=> [$a[0], $a[1]->id],
        );
        return array_slice(array_map(static fn(array $pair): Experience => $pair[1], $ranked), 0, $limit);
    }

    public function redact(string $text, string $replacement): int {
        $changed = 0;
        foreach ($this->records as $id => $r) {
            if ($text !== '' && str_contains($r->goal, $text)) {
                $this->records[$id] = new Experience($r->conversationId, $r->principalId, $r->runId, str_replace($text, $replacement, $r->goal), $r->role, $r->profile, $r->model, $r->firstModel, $r->status, $r->verdict, $r->reason, $r->turns, $r->tokens, $r->durationMs, $r->id, $r->createdAt);
                $changed++;
            }
        }
        return $changed;
    }
}
