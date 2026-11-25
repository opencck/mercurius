<?php

declare(strict_types=1);

namespace OpenCCK\Test\Unit\App\Agent\Tool;

use Amp\NullCancellation;
use Amp\PHPUnit\AsyncTestCase;
use OpenCCK\App\Agent\ConversationAudience;
use OpenCCK\App\Agent\Tool\Memory\ExperienceSearchTool;
use OpenCCK\App\Agent\Tool\Memory\HistorySearchTool;
use OpenCCK\App\Agent\Tool\ToolContext;
use OpenCCK\Domain\Agent\Experience;
use OpenCCK\Domain\Entity\Member;
use OpenCCK\Domain\Entity\MemberKind;
use OpenCCK\Domain\Entity\Role;
use OpenCCK\Test\Support\InMemoryConversationRepository;
use OpenCCK\Test\Support\InMemoryEpisodeRepository;
use OpenCCK\Test\Support\InMemoryExperienceRepository;

final class HistoryToolsTest extends AsyncTestCase {
    private InMemoryConversationRepository $conversations;
    private InMemoryEpisodeRepository $episodes;
    private InMemoryExperienceRepository $experience;
    private ConversationAudience $audience;
    private Member $owner;
    private Member $guest;

    protected function setUp(): void {
        parent::setUp();
        $this->conversations = new InMemoryConversationRepository();
        $this->episodes = new InMemoryEpisodeRepository();
        $this->experience = new InMemoryExperienceRepository();
        $this->audience = new ConversationAudience($this->conversations);
        $this->owner = new Member(1, MemberKind::Human, Role::owner(), null);
        $this->guest = new Member(2, MemberKind::Human, Role::user(), 'Гость');
    }

    private function conversation(int $principal, string $chatId, bool $private): int {
        $id = $this->conversations->openConversation($principal, 'telegram', $chatId);
        $this->conversations->close('telegram', $chatId);
        $this->episodes->conversations[$id] = ['telegram', $chatId];
        $this->experience->conversations[$id] = ['telegram', $chatId];
        $this->audience->remember($id, $private);
        return $id;
    }

    /** @param array<string, mixed> $args */
    private function history(Member $who, int $conversationId, array $args): string {
        $result = (new HistorySearchTool($this->episodes, $this->audience))->execute($args, new ToolContext(1, $conversationId, $who, new NullCancellation()));
        return $result->content;
    }

    public function testOwnerInPrivateSearchesEverywhereOthersOnlyTheirChat(): void {
        $ownerChat = $this->conversation(1, '42', true);
        $group = $this->conversation(1, '-100', false);
        $this->episodes->addMessage($ownerChat, 'user', 'Пароль от роутера поменяли, nginx перезапущен');
        $this->episodes->addMessage($group, 'assistant', 'В группе тоже про nginx');
        $ownerNow = $this->conversation(1, '42', true);
        $groupNow = $this->conversation(1, '-100', false);

        $owner = $this->history($this->owner, $ownerNow, ['query' => 'nginx']);
        self::assertStringContainsString('чат telegram:-100', $owner);
        self::assertStringContainsString('Пароль от роутера', $owner);
        self::assertSame('В истории ничего не нашлось.', $this->history($this->owner, $ownerChat, ['query' => 'роутера']), 'the conversation itself is not searched');

        $inGroup = $this->history($this->owner, $groupNow, ['query' => 'nginx']);
        self::assertStringContainsString('В группе тоже', $inGroup);
        self::assertStringNotContainsString('Пароль от роутера', $inGroup, 'the owner writing in a group does not open the private chat to it');
        self::assertStringNotContainsString('чат telegram', $inGroup);
    }

    public function testRecalledMemoryAndSecretsDoNotComeBack(): void {
        $chat = $this->conversation(1, '42', true);
        $this->episodes->addMessage($chat, 'user', "ключ nginx sk-or-v1-0123456789abcdef0123456789abcdef0123456789abcdef\n\n<untrusted-data source=\"memory\">\n[#3] секрет владельца\n</untrusted-data>");

        $text = $this->history($this->owner, $this->conversation(1, '42', true), ['query' => 'nginx']);

        self::assertStringNotContainsString('секрет владельца', $text);
        self::assertStringNotContainsString('0123456789abcdef0123', $text);
        self::assertMatchesRegularExpression('/\[беседа #\d+, сообщение #1, \d{4}-\d\d-\d\d \d\d:\d\d, пользователь, чат telegram:42\]/u', $text);
    }

    public function testReadsAroundAFoundMessageOnlyWhereAllowed(): void {
        $ownerChat = $this->conversation(1, '42', true);
        $guestChat = $this->conversation(2, '77', true);
        $ids = [];
        foreach (['раз', 'два', 'три'] as $word) {
            $ids[] = $this->episodes->addMessage($ownerChat, 'user', $word);
        }

        $text = $this->history($this->owner, $ownerChat, ['conversation_id' => $ownerChat, 'message_id' => $ids[1]]);
        self::assertSame(3, substr_count($text, '[беседа'));

        $guest = (new HistorySearchTool($this->episodes, $this->audience))->execute(['conversation_id' => $ownerChat, 'message_id' => $ids[1]], new ToolContext(1, $guestChat, $this->guest, new NullCancellation()));
        self::assertTrue($guest->isError);
        self::assertStringNotContainsString('два', $guest->content);
    }

    public function testNeedsAQueryOrAMessage(): void {
        $chat = $this->conversation(1, '42', true);

        self::assertStringContainsString('Нужен query', $this->history($this->owner, $chat, []));
        self::assertSame('В истории ничего не нашлось.', $this->history($this->owner, $chat, ['query' => 'ничего такого']));
    }

    public function testExcerptIsTakenAroundTheWord(): void {
        $text = str_repeat('а ', 400) . 'бэкапы базы ' . str_repeat('б ', 400);

        $cut = HistorySearchTool::cut($text, ['бэкап'], 100);

        self::assertStringContainsString('бэкапы базы', $cut);
        self::assertStringStartsWith('…', $cut);
        self::assertStringEndsWith('…', $cut);
        self::assertSame('коротко', HistorySearchTool::cut('коротко', ['x'], 100));
    }

    public function testExperienceShowsHowPartsWent(): void {
        $chat = $this->conversation(1, '42', true);
        $other = $this->conversation(2, '77', true);
        $this->experience->add(new Experience($chat, 1, 9, 'Сравнить тарифы: хостинг', 'cheap', ['text' => 6], 'openai:b', 'openai:a', 'done', 'принято', null, 3, 9000, 12500));
        $this->experience->add(new Experience($other, 2, 10, 'Чужая цель про хостинг', 'main', [], 'm', null, 'failed', null, 'лимит ходов', 1, 1, 1));

        $tool = new ExperienceSearchTool($this->experience, $this->audience);
        $owner = $tool->execute(['query' => 'хостинг'], new ToolContext(1, $chat, $this->owner, new NullCancellation()))->content;
        $guest = $tool->execute(['query' => 'хостинг'], new ToolContext(1, $other, $this->guest, new NullCancellation()))->content;

        self::assertStringContainsString('роль cheap · openai:b (каскад с openai:a) · done, проверка: принято · ходов 3, токенов 9000, 12.5 с', $owner);
        self::assertStringContainsString('цель: Сравнить тарифы: хостинг', $owner);
        self::assertStringContainsString('профиль: text 6', $owner);
        self::assertStringContainsString('Чужая цель', $owner, 'the owner in private sees all experience');
        self::assertStringNotContainsString('Сравнить тарифы', $guest);
        self::assertStringContainsString('failed, лимит ходов', $guest);
    }
}
