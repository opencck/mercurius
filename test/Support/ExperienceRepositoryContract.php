<?php

declare(strict_types=1);

namespace OpenCCK\Test\Support;

use Amp\PHPUnit\AsyncTestCase;
use OpenCCK\Domain\Agent\Experience;
use OpenCCK\Domain\Agent\HistoryScope;
use OpenCCK\Domain\Repository\ExperienceRepository;

/**
 * Behaviour every experience store must have: the in-memory fake locally, PostgreSQL on the server.
 */
abstract class ExperienceRepositoryContract extends AsyncTestCase {
    protected ExperienceRepository $experience;

    abstract protected function conversation(string $channel, string $chatId): int;

    public function testRecordsComeBackWhole(): void {
        $chat = $this->conversation('telegram', '10');
        $id = $this->experience->add(new Experience($chat, null, null, 'Найти цены на хостинг', 'cheap', ['text' => 6, 'error_cost' => 8], 'openai:gpt-b', 'openai:gpt-a', 'done', 'принято', null, 4, 12000, 4500));

        $found = $this->experience->search([], HistoryScope::all(), 5);

        self::assertCount(1, $found);
        $record = $found[0];
        self::assertSame($id, $record->id);
        self::assertSame($chat, $record->conversationId);
        self::assertSame('Найти цены на хостинг', $record->goal);
        self::assertSame('cheap', $record->role);
        self::assertSame(['text' => 6, 'error_cost' => 8], $record->profile);
        self::assertSame('openai:gpt-b', $record->model);
        self::assertSame('openai:gpt-a', $record->firstModel);
        self::assertSame('done', $record->status);
        self::assertSame('принято', $record->verdict);
        self::assertNull($record->reason);
        self::assertSame([4, 12000, 4500], [$record->turns, $record->tokens, $record->durationMs]);
        self::assertNotNull($record->createdAt);
    }

    public function testSearchByWordsModelAndChat(): void {
        $mine = $this->conversation('telegram', '10');
        $other = $this->conversation('telegram', '20');
        $hosting = $this->experience->add(self::record($mine, 'Сравнить цены на хостинг', 'a'));
        $backup = $this->experience->add(self::record($mine, 'Проверить бэкап', 'b'));
        $foreign = $this->experience->add(self::record($other, 'Цены на хостинг у конкурентов', 'a'));

        self::assertSame([$hosting], self::ids($this->experience->search(['хостинг'], HistoryScope::chat('telegram', '10'), 5)));
        self::assertSame([$foreign, $hosting], self::ids($this->experience->search(['хостинг'], HistoryScope::all(), 5)));
        self::assertSame([$backup, $hosting], self::ids($this->experience->search([], HistoryScope::chat('telegram', '10'), 5)));
        self::assertSame([$backup], self::ids($this->experience->search([], HistoryScope::all(), 5, 'b')));
        self::assertSame([$foreign], self::ids($this->experience->search([], HistoryScope::all(), 1)));
    }

    public function testModelFilterMatchesTheFirstTryToo(): void {
        $chat = $this->conversation('telegram', '10');
        $id = $this->experience->add(new Experience($chat, null, null, 'цель', 'main', [], 'strong', 'cheap', 'done', null, null, 1, 1, 1));

        self::assertSame([$id], self::ids($this->experience->search([], HistoryScope::all(), 5, 'cheap')));
    }

    public function testRedactReplacesTheTextInGoals(): void {
        $chat = $this->conversation('telegram', '10');
        $this->experience->add(self::record($chat, 'Проверить [#7] сервер pve-01 в Хельсинки', 'a'));
        $this->experience->add(self::record($chat, 'Другое', 'a'));

        self::assertSame(1, $this->experience->redact('[#7] сервер pve-01 в Хельсинки', '[#7] (забыто)'));
        self::assertSame(['Другое', 'Проверить [#7] (забыто)'], array_map(static fn(Experience $e): string => $e->goal, $this->experience->search([], HistoryScope::all(), 5)));
    }

    private static function record(int $conversationId, string $goal, string $model): Experience {
        return new Experience($conversationId, null, null, $goal, 'main', [], $model, null, 'done', null, null, 1, 100, 1000);
    }

    /**
     * @param list<Experience> $records
     * @return list<int>
     */
    private static function ids(array $records): array {
        return array_map(static fn(Experience $e): int => $e->id, $records);
    }
}
