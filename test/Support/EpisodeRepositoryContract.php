<?php

declare(strict_types=1);

namespace OpenCCK\Test\Support;

use Amp\PHPUnit\AsyncTestCase;
use OpenCCK\Domain\Agent\EpisodeMessage;
use OpenCCK\Domain\Agent\HistoryScope;
use OpenCCK\Domain\Repository\EpisodeRepository;

/**
 * Behaviour every episode store must have: the in-memory fake locally, PostgreSQL on the server.
 */
abstract class EpisodeRepositoryContract extends AsyncTestCase {
    protected EpisodeRepository $episodes;

    abstract protected function conversation(string $channel, string $chatId): int;

    abstract protected function message(int $conversationId, string $role, string $content): int;

    public function testSearchStaysWithinTheChat(): void {
        $mine = $this->conversation('telegram', '10');
        $other = $this->conversation('telegram', '20');
        $hit = $this->message($mine, 'user', 'Настроили nginx на сервере');
        $foreign = $this->message($other, 'user', 'У нас тоже nginx');

        self::assertSame([$hit], self::ids($this->episodes->search(['nginx'], HistoryScope::chat('telegram', '10'), 8, 2)));
        self::assertSame([$foreign, $hit], self::ids($this->episodes->search(['nginx'], HistoryScope::all(), 8, 2)));
    }

    public function testRecalledMemoryWorkersAndToolResultsAreNotSearched(): void {
        $chat = $this->conversation('telegram', '10');
        $worker = $this->conversation('worker', 'run1-call-1');
        $this->message($chat, 'user', "Привет\n\n<untrusted-data source=\"memory\">\nnginx стоит на pve-01\n</untrusted-data>");
        $this->message($chat, 'tool', 'nginx: active');
        $this->message($worker, 'assistant', 'nginx перезапущен');

        self::assertSame([], $this->episodes->search(['nginx'], HistoryScope::all(), 8, 2));
    }

    public function testMoreWordsRankFirstAndEachConversationIsCapped(): void {
        $a = $this->conversation('telegram', '10');
        $b = $this->conversation('telegram', '11');
        $one = $this->message($a, 'user', 'бэкап базы');
        $two = $this->message($a, 'assistant', 'бэкап готов');
        $three = $this->message($a, 'user', 'бэкап снова');
        $both = $this->message($b, 'user', 'бэкап nginx');

        $found = self::ids($this->episodes->search(['бэкап nginx'], HistoryScope::all(), 8, 2));

        self::assertSame($both, $found[0]);
        self::assertCount(3, $found);
        self::assertNotContains($one, $found);
        self::assertSame([$both, $three, $two], $found);
        self::assertCount(1, $this->episodes->search(['бэкап'], HistoryScope::all(), 1, 2));
    }

    public function testQueryOfPunctuationFindsNothing(): void {
        $chat = $this->conversation('telegram', '10');
        $this->message($chat, 'user', 'что-то');

        self::assertSame([], $this->episodes->search(['!!! & | ()'], HistoryScope::all(), 8, 2));
    }

    public function testAroundReadsAWindowWithinTheScope(): void {
        $chat = $this->conversation('telegram', '10');
        $ids = [];
        foreach (['раз', 'два', 'три', 'четыре', 'пять'] as $i => $word) {
            $ids[] = $this->message($chat, $i % 2 === 0 ? 'user' : 'assistant', $word);
        }
        $this->message($chat, 'tool', 'результат');

        $window = $this->episodes->around($chat, $ids[2], HistoryScope::chat('telegram', '10'), 1);

        self::assertSame([$ids[1], $ids[2], $ids[3]], self::ids($window));
        self::assertSame('три', $window[1]->content);
        self::assertSame('assistant', $window[0]->role);
        self::assertSame([$ids[0], $ids[1], $ids[2], $ids[3], $ids[4]], self::ids($this->episodes->around($chat, $ids[2], HistoryScope::all(), 4)));
        self::assertSame([], $this->episodes->around($chat, $ids[2], HistoryScope::chat('telegram', '99'), 1));
        self::assertSame([], $this->episodes->around($chat + 1000, $ids[2], HistoryScope::all(), 1));
    }

    /**
     * @param list<EpisodeMessage> $messages
     * @return list<int>
     */
    private static function ids(array $messages): array {
        return array_map(static fn(EpisodeMessage $m): int => $m->id, $messages);
    }
}
