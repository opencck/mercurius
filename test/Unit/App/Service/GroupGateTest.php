<?php

declare(strict_types=1);

namespace OpenCCK\Test\Unit\App\Service;

use Amp\PHPUnit\AsyncTestCase;
use DateTimeImmutable;
use Mercurius\Messenger\IncomingCallback;
use Mercurius\Messenger\IncomingMembership;
use Mercurius\Messenger\IncomingMessage;
use OpenCCK\App\Service\GroupGate;
use OpenCCK\Domain\Group\Group;
use OpenCCK\Test\Support\AgentStack;
use OpenCCK\Test\Support\InMemoryGroupRepository;
use OpenCCK\Test\Support\ScriptedLlm;
use Psr\Log\NullLogger;

use function Amp\delay;

final class GroupGateTest extends AsyncTestCase {
    private const GROUP = '-100500';
    private const BOT = '700100200';

    private AgentStack $stack;
    private InMemoryGroupRepository $groups;
    private GroupGate $gate;
    private int $id = 100;

    private function stack(ScriptedLlm $llm, float $quiet = 0.0, ?string $botName = 'mrcuribot'): void {
        $this->stack = new AgentStack($llm, quietDebounce: $quiet);
        $this->stack->principals->ensureOwner('telegram', '42', 'Owner');
        $this->groups = new InMemoryGroupRepository();
        $this->gate = new GroupGate($this->groups, $this->stack->principals, $this->stack->channel, '42', self::BOT, $botName, new NullLogger(), visitors: $this->stack->visitors);
        $this->stack->useGroups($this->gate);
    }

    /** @param array<array-key, mixed> $extra */
    private function say(string $text, string $userId = '42', array $extra = [], ?string $thread = null): void {
        $id = (string) ++$this->id;
        $this->stack->chat->handle(new IncomingMessage('telegram', self::GROUP, $userId, 'u' . $userId, 'User ' . $userId, $text, $id, null, false, new DateTimeImmutable(), $id, $thread, raw: $extra + ['chat' => ['id' => (int) self::GROUP, 'title' => 'Дом', 'type' => 'supergroup']]));
        delay(0.02);
    }

    private function press(string $data): void {
        $this->gate->handleCallback(new IncomingCallback('telegram', 'cb' . ++$this->id, '42', '42', 'Owner', '7', $data, (string) $this->id));
    }

    private function membership(string $status, string $type = 'supergroup', string $old = 'left'): void {
        $this->gate->handleMembership(new IncomingMembership('telegram', self::GROUP, 'Дом', $type, '42', 'Owner', $status, $old, (string) ++$this->id));
    }

    /** @return list<string> */
    private function cards(): array {
        return array_values(array_filter($this->stack->channel->texts('send'), static fn(string $t): bool => str_contains($t, 'Меня добавили в группу «Дом»')));
    }

    public function testTheCardComesWhenTheBotIsAddedAndARemovalAsksAgainNextTime(): void {
        $this->stack(new ScriptedLlm([]));

        $this->membership('member');
        $this->membership('administrator', old: 'member');
        self::assertCount(1, $this->cards(), 'the card comes at once, before anyone writes; one card');

        $this->press('gr:' . self::GROUP . ':m');
        $this->membership('kicked', old: 'member');
        self::assertSame(Group::PENDING, $this->groups->find('telegram', self::GROUP)?->status);
        self::assertContains('Меня убрали из группы «Дом».', $this->stack->channel->texts('send'));

        $this->membership('member');
        self::assertCount(2, $this->cards(), 'added again — the owner decides again');
        $this->membership('member', 'private');
        self::assertCount(2, $this->cards(), 'a private chat is not a group');
    }

    public function testARefusedGroupIsLeftWhenTheBotIsAddedAgain(): void {
        $this->stack(new ScriptedLlm([]));
        $this->membership('member');
        $this->press('gr:' . self::GROUP . ':r');

        $this->membership('member');

        self::assertSame([self::GROUP, self::GROUP], $this->stack->channel->left, 'left on the refusal and again when added back');
        self::assertCount(1, $this->cards());
    }

    public function testEachForumTopicHasItsOwnConversationButRepliesInAGroupDoNot(): void {
        $this->stack(new ScriptedLlm([ScriptedLlm::text('a'), ScriptedLlm::text('b'), ScriptedLlm::text('c'), ScriptedLlm::text('d')]));
        $this->say('первое');
        $this->press('gr:' . self::GROUP . ':m');

        $this->say('@mrcuribot в теме 12', thread: '12', extra: ['is_topic_message' => true]);
        $this->say('@mrcuribot в теме 13', thread: '13', extra: ['is_topic_message' => true]);
        $this->say('@mrcuribot ответом в обычной ветке', thread: '7');
        $this->say('@mrcuribot в General');

        $first = $this->stack->conversations->openConversation(1, 'telegram', self::GROUP, '12');
        $second = $this->stack->conversations->openConversation(1, 'telegram', self::GROUP, '13');
        $general = $this->stack->conversations->openConversation(1, 'telegram', self::GROUP);
        self::assertCount(3, array_unique([$first, $second, $general]), 'a topic is a conversation of its own');
        self::assertSame('12', $this->stack->conversations->meta($first)?->threadId);
        self::assertSame('', $this->stack->conversations->meta($general)?->threadId, 'a reply thread outside a forum is not a topic');
        self::assertCount(4, $this->stack->llm->requests);
    }

    public function testAForumIsRecognisedAndATopicSomeoneOpensBecomesAProject(): void {
        $this->stack(new ScriptedLlm([]));
        $projects = new \OpenCCK\Test\Support\InMemoryProjectRepository();
        $this->gate = new GroupGate($this->groups, $this->stack->principals, $this->stack->channel, '42', self::BOT, 'mrcuribot', new NullLogger(), new \OpenCCK\App\Service\ForumTopics($this->stack->channel, $this->groups, $projects, new NullLogger()));
        $this->stack->useGroups($this->gate);
        $forum = ['chat' => ['id' => (int) self::GROUP, 'title' => 'Дом', 'type' => 'supergroup', 'is_forum' => true]];
        $this->say('первое', extra: $forum);
        $this->press('gr:' . self::GROUP . ':m');
        self::assertTrue($this->groups->find('telegram', self::GROUP)?->forum);

        $this->say('', thread: '88', extra: $forum + ['is_topic_message' => true, 'forum_topic_created' => ['name' => 'Ремонт', 'icon_color' => 0]]);

        self::assertSame('Ремонт', $projects->active('telegram', self::GROUP, '88')?->name);
        self::assertCount(0, $this->stack->llm->requests, 'opening a topic is not a question to the agent');
    }

    private function message(string $text, string $userId, bool $bot = false, string $userName = ''): IncomingMessage {
        $id = (string) ++$this->id;
        return new IncomingMessage('telegram', self::GROUP, $userId, $userName === '' ? 'u' . $userId : $userName, 'User ' . $userId, $text, $id, null, false, new DateTimeImmutable(), $id, raw: ['chat' => ['id' => (int) self::GROUP, 'title' => 'Дом', 'type' => 'supergroup']], fromBot: $bot);
    }

    public function testABotIsAVisitorAnsweredOnlyWhenAddressedAndNotForEver(): void {
        // spec: brief/channels.md#группа — another bot is a visitor; it must address the agent, and the guard ends a loop of bots
        $this->stack(new ScriptedLlm([]));
        $this->say('первое');
        $this->press('gr:' . self::GROUP . ':f');
        $this->gate->useBots(new \OpenCCK\App\Service\BotLoopGuard());

        self::assertNull($this->gate->route($this->message('@mrcuribot привет', '901', true)), 'a new bot waits for the owner like a person');
        $bot = $this->stack->principals->findByIdentity('telegram', '901');
        self::assertNotNull($bot);
        $this->stack->principals->decideVisitor($bot->id, \OpenCCK\Domain\Entity\Role::reader(), \OpenCCK\Domain\Entity\MemberStatus::Active);

        self::assertNull($this->gate->route($this->message('просто болтаю', '901', true)), 'a free group still wants a bot to address the agent');
        $answered = 0;
        for ($i = 1; $i <= 6; $i++) {
            $answered += $this->gate->route($this->message('@mrcuribot вопрос ' . $i, '901', true)) === null ? 0 : 1;
        }
        self::assertSame(4, $answered, 'four hops, then silence');
        self::assertNotNull($this->gate->route($this->message('@mrcuribot что там?', '42')), 'a person is answered');
        self::assertNotNull($this->gate->route($this->message('@mrcuribot ещё вопрос', '901', true)), 'and a new exchange begins');
    }

    public function testAParticipantNamesWhomTheAgentIgnoresInTheGroup(): void {
        // spec: brief/channels.md#группа — participants name bots and visitors the agent does not hear in a group
        $this->stack(new ScriptedLlm([]));
        $this->say('первое');
        $this->press('gr:' . self::GROUP . ':f');
        $ignores = new \OpenCCK\Test\Support\InMemoryGroupIgnoreRepository();
        $this->gate->useIgnores($ignores);
        $tool = new \OpenCCK\App\Agent\Tool\Messaging\GroupIgnoreTool($ignores, $this->stack->conversations, $this->groups);
        $owner = $this->stack->principals->findByIdentity('telegram', '42');
        self::assertNotNull($owner);
        $conversation = $this->stack->conversations->openConversation($owner->id, 'telegram', self::GROUP);
        $context = new \OpenCCK\App\Agent\Tool\ToolContext(1, $conversation, $owner, new \Amp\NullCancellation());

        self::assertStringContainsString('noisy_bot', $tool->execute(['action' => 'add', 'who' => '@Noisy_Bot'], $context)->content);
        self::assertNull($this->gate->route($this->message('@mrcuribot слушай меня', '901', true, 'noisy_bot')), 'ignored by name');
        self::assertStringContainsString('noisy_bot', $tool->execute(['action' => 'list'], $context)->content);
        $tool->execute(['action' => 'remove', 'who' => 'noisy_bot'], $context);
        self::assertSame([], $ignores->list('telegram', self::GROUP));
        $private = new \OpenCCK\App\Agent\Tool\ToolContext(1, $this->stack->conversations->openConversation($owner->id, 'telegram', '42'), $owner, new \Amp\NullCancellation());
        self::assertTrue($tool->execute(['action' => 'add', 'who' => '@x'], $private)->isError, 'only in a group');
    }

    public function testAStrangerInTheGroupBecomesAVisitorTheOwnerCanDecideAbout(): void {
        $this->stack(new ScriptedLlm([]));
        $this->say('первое');
        $this->press('gr:' . self::GROUP . ':f');

        $this->say('Всем привет, я Роман', '77');
        $this->say('@mrcuribot а мне ответишь?', '77');

        $cards = array_values(array_filter($this->stack->channel->texts('send'), static fn(string $t): bool => str_starts_with($t, 'Новый собеседник')));
        self::assertCount(1, $cards, 'one card, at the first message');
        self::assertStringContainsString('«Дом»', $cards[0]);
        self::assertStringContainsString('Всем привет, я Роман', $cards[0]);
        self::assertSame(\OpenCCK\Domain\Entity\MemberStatus::Pending, $this->stack->principals->findByIdentity('telegram', '77')?->status);
        self::assertCount(0, $this->stack->llm->requests, 'a stranger is still not answered');
    }

    public function testTheTalkOfATopicStaysInItsTopicAndSurvivesARestart(): void {
        $this->stack(new ScriptedLlm([ScriptedLlm::text('a'), ScriptedLlm::text('b')]));
        $talk = new \OpenCCK\Infrastructure\Repository\InMemoryGroupTalkRepository();
        $this->gate = new GroupGate($this->groups, $this->stack->principals, $this->stack->channel, '42', self::BOT, 'mrcuribot', new NullLogger(), talk: $talk);
        $this->stack->useGroups($this->gate);
        $topic = static fn(string $id): array => ['is_topic_message' => true];
        $this->say('первое');
        $this->press('gr:' . self::GROUP . ':m');
        $this->say('в теме 12 про свет', thread: '12', extra: $topic('12'));
        $this->say('в теме 13 про воду', thread: '13', extra: $topic('13'));

        // a restart: a new gate over the same store
        $this->gate = new GroupGate($this->groups, $this->stack->principals, $this->stack->channel, '42', self::BOT, 'mrcuribot', new NullLogger(), talk: $talk);
        $this->stack->useGroups($this->gate);
        $this->say('@mrcuribot что там?', thread: '12', extra: $topic('12'));

        $prompt = array_slice($this->stack->llm->requests[0]->messages, -1)[0]->content ?? '';
        self::assertStringContainsString('в теме 12 про свет', $prompt, 'the talk survived the restart');
        self::assertStringNotContainsString('в теме 13 про воду', $prompt, 'another topic is another talk');
    }

    public function testABurstOfTalkInAFreeGroupIsOneRunAndAnAddressIsNotKeptWaiting(): void {
        $this->stack(new ScriptedLlm([ScriptedLlm::text('[SILENT]'), ScriptedLlm::text('ок')]), quiet: 0.3);
        $this->say('первое');
        $this->press('gr:' . self::GROUP . ':f');

        $this->say('раз');
        $this->say('два');
        $this->say('три');
        self::assertCount(0, $this->stack->llm->requests, 'the group is still talking');
        delay(0.5);

        self::assertCount(1, $this->stack->llm->requests, 'one run for the burst');
        $prompt = array_slice($this->stack->llm->requests[0]->messages, -1)[0]->content ?? '';
        self::assertStringContainsString('раз', $prompt);
        self::assertStringContainsString('три', $prompt);

        $this->say('@mrcuribot а ты что думаешь?');
        delay(0.1);
        self::assertCount(2, $this->stack->llm->requests, 'someone asked: no waiting');
    }

    public function testAFreeGroupOverItsDailyTokensOnlyAnswersWhenAsked(): void {
        $this->stack(new ScriptedLlm([ScriptedLlm::text('ок')]));
        $this->gate = new GroupGate($this->groups, $this->stack->principals, $this->stack->channel, '42', self::BOT, 'mrcuribot', new NullLogger(), spentToday: static fn(string $channel, string $chatId): int => 250_000, freeDailyTokens: 200_000);
        $this->stack->useGroups($this->gate);
        $this->say('первое');
        $this->press('gr:' . self::GROUP . ':f');

        $member = $this->stack->principals->createVisitor('telegram', '77', 'Роман');
        $this->stack->principals->decideVisitor($member->id, \OpenCCK\Domain\Entity\Role::user(), \OpenCCK\Domain\Entity\MemberStatus::Active);

        $this->say('просто болтаем', '77');
        self::assertCount(0, $this->stack->llm->requests, 'over the limit the agent does not join in by itself');
        $this->say('@mrcuribot а сколько времени?', '77');
        self::assertCount(1, $this->stack->llm->requests, 'but answers when asked');
    }

    public function testTheOwnerIsWarnedOnceAndMayRaiseOrLiftTheLimit(): void {
        $this->stack(new ScriptedLlm([ScriptedLlm::text('ок'), ScriptedLlm::text('ок')]));
        $this->gate = new GroupGate($this->groups, $this->stack->principals, $this->stack->channel, '42', self::BOT, 'mrcuribot', new NullLogger(), spentToday: static fn(string $channel, string $chatId): int => 250_000, freeDailyTokens: 200_000);
        $this->stack->useGroups($this->gate);
        $this->say('первое');
        $this->press('gr:' . self::GROUP . ':f');
        $member = $this->stack->principals->createVisitor('telegram', '77', 'Роман');
        $this->stack->principals->decideVisitor($member->id, \OpenCCK\Domain\Entity\Role::user(), \OpenCCK\Domain\Entity\MemberStatus::Active);

        $this->say('болтаем', '77');
        $this->say('и ещё', '77');
        $warnings = array_values(array_filter($this->stack->channel->texts('send'), static fn(string $t): bool => str_contains($t, 'дневного лимита')));
        self::assertCount(1, $warnings, 'one warning a day');
        self::assertStringContainsString('250000', $warnings[0]);
        self::assertStringContainsString('200000', $warnings[0]);

        $this->press('gr:' . self::GROUP . ':u400000');
        self::assertSame(400_000, $this->groups->find('telegram', self::GROUP)?->dailyTokens);
        $this->say('а теперь?', '77');
        delay(9);
        self::assertCount(1, $this->stack->llm->requests, 'the raised limit lets the agent join in again');

        $this->press('gr:' . self::GROUP . ':n');
        self::assertSame(0, $this->groups->find('telegram', self::GROUP)?->dailyTokens, '0 — no limit for this group');
    }

    public function testTheDailyLimitDoesNotSilenceTheOwner(): void {
        $this->stack(new ScriptedLlm([ScriptedLlm::text('ок')]));
        $this->gate = new GroupGate($this->groups, $this->stack->principals, $this->stack->channel, '42', self::BOT, 'mrcuribot', new NullLogger(), spentToday: static fn(string $channel, string $chatId): int => 250_000, freeDailyTokens: 200_000);
        $this->stack->useGroups($this->gate);
        $this->say('первое');
        $this->press('gr:' . self::GROUP . ':f');

        $this->say('спроси у ребят, как дела');
        delay(9);

        self::assertCount(1, $this->stack->llm->requests, 'the owner pays for the agent: their words are answered past the limit');
        $runs = array_values($this->stack->runs->runs);
        // spec: brief/agent-loop.md#запуск-начатый-внешними-данными — the owner's own words are not outside data
        self::assertNull($runs[0]->outside ?? null, 'only the owner spoke in the group: saved grants apply as in the private chat');
    }

    public function testAGroupWhereSomeoneElseSpokeMarksTheOwnersRunToo(): void {
        $this->stack(new ScriptedLlm([ScriptedLlm::text('ок')]));
        $this->say('первое');
        $this->press('gr:' . self::GROUP . ':f');
        $this->say('а я гость', '77');

        $this->say('@mrcuribot что скажешь?');
        delay(0.3);

        $runs = array_values($this->stack->runs->runs);
        $marks = array_map(static fn(\OpenCCK\Domain\Agent\Run $run): ?string => $run->outside, $runs);
        self::assertSame('group chat', array_pop($marks), 'other people\'s words are in the context: only a grant given for that applies');
    }

    public function testANewGroupWaitsForTheOwnerAndARefusalLeaves(): void {
        $this->stack(new ScriptedLlm([]));

        $this->say('@mrcuribot привет');
        $this->say('@mrcuribot ну что?');

        $cards = array_filter($this->stack->channel->texts('send'), static fn(string $t): bool => str_contains($t, 'Меня добавили в группу «Дом»'));
        self::assertCount(1, $cards, 'one card');
        self::assertCount(0, $this->stack->llm->requests, 'silent until the owner decides');

        $this->press('gr:' . self::GROUP . ':r');
        self::assertSame([self::GROUP], $this->stack->channel->left);
        self::assertSame(Group::REFUSED, $this->groups->find('telegram', self::GROUP)?->status);
    }

    public function testInAGroupARunHasNoMoreRightsThanItsWeakestSpeaker(): void {
        $this->stack(new ScriptedLlm([ScriptedLlm::text('раз'), ScriptedLlm::text('два')]));
        $this->stack->registry->register(new \OpenCCK\Test\Support\FakeTool('read_file'));
        $visitor = $this->stack->principals->createVisitor('telegram', '77', 'Гость');
        $this->stack->principals->decideVisitor($visitor->id, \OpenCCK\Domain\Entity\Role::reader(), \OpenCCK\Domain\Entity\MemberStatus::Active);
        $this->say('первое сообщение');
        $this->press('gr:' . self::GROUP . ':m');

        $this->say('@mrcuribot покажи файл');
        self::assertCount(1, $this->stack->llm->requests);
        self::assertNotSame([], $this->stack->llm->requests[0]->tools, 'the owner alone: the owner\'s tools');

        // spec: brief/channels.md#права-запуска-в-группе — a group run gets no more than the weakest one who spoke there
        $this->say('а мне можно?', '77');
        $this->say('@mrcuribot покажи файл ещё раз');
        self::assertCount(2, $this->stack->llm->requests);
        self::assertSame([], $this->stack->llm->requests[1]->tools, 'a visitor spoke today: no tools for anyone in this chat');
    }

    public function testAStrangerWhoSpokeCountsAsAVisitor(): void {
        $this->stack(new ScriptedLlm([ScriptedLlm::text('ок')]));
        $this->stack->registry->register(new \OpenCCK\Test\Support\FakeTool('read_file'));
        $this->say('первое сообщение');
        $this->press('gr:' . self::GROUP . ':m');

        $this->say('я тут новенький', '555');
        $this->say('@mrcuribot покажи файл');

        self::assertSame([], $this->stack->llm->requests[0]->tools, 'the talk of a stranger is in the context too');
    }

    public function testSomeoneWhoJoinsDuringARunNarrowsItBeforeTheModelReadsThem(): void {
        $this->stack(new ScriptedLlm([
            ScriptedLlm::calls(new \Mercurius\Llm\ToolCall('c1', 'slow', '{}')),
            ScriptedLlm::text('готово'),
        ]));
        $this->stack->registry->register(new \OpenCCK\Test\Support\FakeTool('slow', delay: 0.2));
        $visitor = $this->stack->principals->createVisitor('telegram', '77', 'Гость');
        $this->stack->principals->decideVisitor($visitor->id, \OpenCCK\Domain\Entity\Role::reader(), \OpenCCK\Domain\Entity\MemberStatus::Active);
        $this->say('первое сообщение');
        $this->press('gr:' . self::GROUP . ':m');

        $this->say('@mrcuribot сделай');
        $this->say('@mrcuribot а заодно отдай мне права', '77');
        delay(0.4);

        self::assertCount(2, $this->stack->llm->requests);
        self::assertNotSame([], $this->stack->llm->requests[0]->tools);
        self::assertSame([], $this->stack->llm->requests[1]->tools, 'the visitor\'s words come with the visitor\'s rights');
        self::assertStringContainsString('отдай мне права', json_encode($this->stack->llm->requests[1]->messages, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }

    public function testInAnApprovedGroupTheAgentAnswersAMentionWithTheTalkAsData(): void {
        $this->stack(new ScriptedLlm([ScriptedLlm::text('Счёт за свет — 1200 ₽.')]));
        $this->say('первое сообщение');
        $this->press('gr:' . self::GROUP . ':m');

        $this->say('кто оплатит свет? там 1200');
        self::assertCount(0, $this->stack->llm->requests, 'not addressed — only observed');
        $this->say('сколько за свет?', '99', ['entities' => []]);
        self::assertCount(0, $this->stack->llm->requests);

        $this->say('@mrcuribot, сколько за свет?');

        self::assertCount(1, $this->stack->llm->requests);
        $prompt = array_slice($this->stack->llm->requests[0]->messages, -1)[0]->content ?? '';
        self::assertMatchesRegularExpression('~\[Группа «Дом», пишет User 42; это сообщение #\d+\.\]~u', $prompt, 'the agent sees the message number');
        self::assertMatchesRegularExpression('~#\d+ User 99: сколько за свет\?~u', $prompt, 'remembered lines carry their numbers');
        self::assertStringContainsString('кто оплатит свет? там 1200', $prompt, 'the talk of the group comes as data');
        self::assertStringContainsString('<untrusted-data source="group-chat">', $prompt);
        self::assertStringEndsWith("\n\nсколько за свет?", $prompt, 'the mention is dropped');
    }

    public function testTheBotNameLearnedAfterStartMakesMentionsHeard(): void {
        // spec: brief/channels.md#telegram-прокси-и-локальный-сервер-bot-api — Telegram was out of reach at start
        $this->stack(new ScriptedLlm([ScriptedLlm::text('Да.')]), botName: null);
        $this->say('первое сообщение');
        $this->press('gr:' . self::GROUP . ':m');

        $this->say('@mrcuribot, ты тут?');
        self::assertCount(0, $this->stack->llm->requests, 'the name is not known yet');

        $this->gate->knowBotName('mrcuribot');
        $this->say('@mrcuribot, ты тут?');
        self::assertCount(1, $this->stack->llm->requests);
    }

    public function testTheAnswerStaysInTheForumTopicOfTheMessage(): void {
        $this->stack(new ScriptedLlm([ScriptedLlm::text('В теме.')]));
        $this->say('x');
        $this->press('gr:' . self::GROUP . ':m');
        $before = count($this->stack->channel->threads);

        $this->say('@mrcuribot ответь тут', thread: '77');

        $threads = array_slice($this->stack->channel->threads, $before);
        self::assertNotSame([], $threads);
        self::assertSame(['77'], array_values(array_unique($threads)), 'every message of the run goes to topic 77');
    }

    public function testAStrangerIsNotAnsweredAndAReplyToTheBotCounts(): void {
        $this->stack(new ScriptedLlm([ScriptedLlm::text('да')]));
        $this->say('x');
        $this->press('gr:' . self::GROUP . ':m');

        $this->say('@mrcuribot помоги', '99');
        self::assertCount(0, $this->stack->llm->requests, 'a stranger in a group is not answered');
        self::assertCount(1, array_filter($this->stack->channel->texts('send'), static fn(string $t): bool => str_contains($t, 'Новый собеседник')), 'but the owner gets a card to decide about them');

        $this->say('а точно?', '42', ['reply_to_message' => ['message_id' => 5, 'from' => ['id' => (int) self::BOT, 'is_bot' => true]]]);
        self::assertCount(1, $this->stack->llm->requests);
    }

    public function testInTheFreeModeTheAgentMayStaySilent(): void {
        $this->stack(new ScriptedLlm([ScriptedLlm::text('[SILENT]')]));
        $this->say('x');
        $this->press('gr:' . self::GROUP . ':f');

        $this->say('всем привет');

        self::assertCount(1, $this->stack->llm->requests, 'free — the agent decides');
        self::assertStringContainsString('ответь ровно [SILENT]', array_slice($this->stack->llm->requests[0]->messages, -1)[0]->content ?? '');
        self::assertSame([], array_filter($this->stack->channel->texts('send'), static fn(string $t): bool => str_contains($t, 'SILENT')));
        self::assertSame([], array_filter($this->stack->channel->texts('edit'), static fn(string $t): bool => str_contains($t, 'SILENT')));
    }

    public function testAnAgentThatActedInAFreeGroupReportsInsteadOfStayingSilent(): void {
        // spec: brief/channels.md#группа — silence is for a turn with nothing to say, not for one that acted
        $this->stack(new ScriptedLlm([
            ScriptedLlm::calls(new \Mercurius\Llm\ToolCall('c1', 'skill_setup', '{}')),
            ScriptedLlm::text('[SILENT]'),
            ScriptedLlm::text('Подключил скилл к проекту.'),
        ]));
        $this->stack->registry->register(new \OpenCCK\Test\Support\FakeTool('skill_setup', danger: \OpenCCK\Domain\Agent\DangerClass::Write));
        $this->say('x');
        $this->press('gr:' . self::GROUP . ':f');

        $this->say('в этом проекте используем такой-то скилл');
        $this->stack->scheduler->awaitIdle();

        self::assertCount(3, $this->stack->llm->requests, 'one reminder after the silent answer');
        self::assertContains('Подключил скилл к проекту.', [...$this->stack->channel->texts('send'), ...$this->stack->channel->texts('edit')]);
    }

    public function testAPhotoAddressedToTheAgentInAGroupComesWithItsFile(): void {
        $this->stack(new ScriptedLlm([ScriptedLlm::text('Модель зрения не назначена.')]));
        $intake = new \OpenCCK\App\Agent\Media\AttachmentIntake($this->stack->channel, new \OpenCCK\Test\Support\FakeSandbox(), \OpenCCK\Infrastructure\Config\MediaSettings::fromEnv([]), new \DateTimeZone('+03:00'));
        $this->stack->chat = new \OpenCCK\App\Service\ChatService(new \OpenCCK\App\Service\AccessService($this->stack->principals, 'telegram', '42', $this->stack->visitors), $this->stack->conversations, $this->stack->scheduler, $this->stack->channel, $this->stack->approvalService, groups: $this->gate, intake: $intake, policies: $this->stack->policies);
        $this->say('первое сообщение');
        $this->press('gr:' . self::GROUP . ':m');
        $this->stack->channel->downloads['ph'] = 'JPEG';
        $photo = [new \Mercurius\Messenger\Attachment(\Mercurius\Messenger\AttachmentKind::Photo, 'ph', 'photo-9.jpg', 'image/jpeg', 4, null, 800, 600)];

        $id = (string) ++$this->id;
        $this->stack->chat->handle(new IncomingMessage('telegram', self::GROUP, '42', 'u42', 'User 42', '', $id, null, false, new DateTimeImmutable(), $id, raw: ['chat' => ['id' => (int) self::GROUP, 'title' => 'Дом', 'type' => 'supergroup']], attachments: $photo));
        delay(0.02);
        self::assertCount(0, $this->stack->llm->requests, 'a photo nobody addressed to the agent is not taken');

        $id = (string) ++$this->id;
        $this->stack->chat->handle(new IncomingMessage('telegram', self::GROUP, '42', 'u42', 'User 42', '@mrcuribot что тут?', $id, null, false, new DateTimeImmutable(), $id, raw: ['chat' => ['id' => (int) self::GROUP, 'title' => 'Дом', 'type' => 'supergroup']], attachments: $photo));
        $this->stack->scheduler->awaitIdle();

        $prompt = array_slice($this->stack->llm->requests[0]->messages ?? [], -1)[0]->content ?? '';
        self::assertStringContainsString('photo-9.jpg', $prompt, 'the file came with the message');
        self::assertStringContainsString('MEDIA_VISION_MODELS', $prompt);
    }

    public function testInAFreeGroupTheAgentTakesEveryPhoto(): void {
        $this->stack(new ScriptedLlm([ScriptedLlm::text('[SILENT]')]));
        $intake = new \OpenCCK\App\Agent\Media\AttachmentIntake($this->stack->channel, new \OpenCCK\Test\Support\FakeSandbox(), \OpenCCK\Infrastructure\Config\MediaSettings::fromEnv([]), new \DateTimeZone('+03:00'));
        $this->stack->chat = new \OpenCCK\App\Service\ChatService(new \OpenCCK\App\Service\AccessService($this->stack->principals, 'telegram', '42', $this->stack->visitors), $this->stack->conversations, $this->stack->scheduler, $this->stack->channel, $this->stack->approvalService, groups: $this->gate, intake: $intake, policies: $this->stack->policies);
        $this->say('первое сообщение');
        $this->press('gr:' . self::GROUP . ':f');
        $this->stack->channel->downloads['ph'] = 'JPEG';
        $photo = [new \Mercurius\Messenger\Attachment(\Mercurius\Messenger\AttachmentKind::Photo, 'ph', 'photo-9.jpg', 'image/jpeg', 4, null, 800, 600)];

        $id = (string) ++$this->id;
        $this->stack->chat->handle(new IncomingMessage('telegram', self::GROUP, '42', 'u42', 'User 42', '', $id, null, false, new DateTimeImmutable(), $id, raw: ['chat' => ['id' => (int) self::GROUP, 'title' => 'Дом', 'type' => 'supergroup']], attachments: $photo));
        $this->stack->scheduler->awaitIdle();

        $prompt = array_slice($this->stack->llm->requests[0]->messages ?? [], -1)[0]->content ?? '';
        self::assertStringContainsString('photo-9.jpg', $prompt, 'in the free mode the agent is a full participant: it sees the photo');
    }

    public function testAGroupTurnedIntoASupergroupKeepsTheOwnersDecision(): void {
        // spec: brief/channels.md#как-сделано-группы
        $groups = new \OpenCCK\Test\Support\InMemoryGroupRepository();
        $groups->create('telegram', '-555', 'Дом');
        $groups->decide('telegram', '-555', \OpenCCK\Domain\Group\Group::APPROVED, \OpenCCK\Domain\Group\Group::FREE);
        $groups->create('telegram', '-100777', 'Дом');

        $moved = $groups->move('telegram', '-555', '-100777');

        self::assertNotNull($moved);
        self::assertSame(\OpenCCK\Domain\Group\Group::APPROVED, $moved->status);
        self::assertSame(\OpenCCK\Domain\Group\Group::FREE, $moved->mode);
        self::assertNull($groups->find('telegram', '-555'), 'the record under the old number is gone');
        self::assertCount(1, $groups->all());
        self::assertNull($groups->move('telegram', '-555', '-100777'), 'a group that is not known moves nothing');
    }

    private function joined(string $chat, string $type): void {
        $this->gate->handleMembership(new IncomingMembership('telegram', $chat, 'Дом', $type, '42', 'Owner', 'member', 'left', (string) ++$this->id));
    }

    private function moved(string $from, string $to): void {
        $id = (string) ++$this->id;
        $this->gate->route(new IncomingMessage('telegram', $to, '42', 'u42', 'Owner', '', $id, null, false, new DateTimeImmutable(), $id, raw: ['chat' => ['id' => (int) $to, 'title' => 'Дом', 'type' => 'supergroup'], 'migrate_from_chat_id' => (int) $from]));
    }

    /** @return list<string> ids of the cards sent to the owner */
    private function cardIds(): array {
        $ids = [];
        foreach ($this->stack->channel->buttons as $id => $rows) {
            if (str_starts_with($rows[0][0]->data ?? '', 'gr:') && str_ends_with($rows[0][0]->data ?? '', ':m')) {
                $ids[] = (string) $id;
            }
        }
        return $ids;
    }

    public function testTheOldCardOfAMovedGroupGoesAndStillDecidesForItsNewNumber(): void {
        // spec: brief/channels.md#как-сделано-группы — a group turned into a supergroup is the same group under a new number
        $this->stack(new ScriptedLlm([]));
        $this->joined('-555', 'group');
        $this->joined('-100777', 'supergroup');
        $ids = $this->cardIds();
        self::assertCount(2, $ids);
        [$old, $new] = [$ids[0] ?? '', $ids[1] ?? ''];

        $this->moved('-555', '-100777');
        self::assertContains(['42', $old], $this->stack->channel->deleted, 'the card of the old number is no longer true: it goes');
        self::assertNotContains(['42', $new], $this->stack->channel->deleted);

        $this->press('gr:-555:f');
        $group = $this->groups->find('telegram', '-100777');
        self::assertSame([Group::APPROVED, Group::FREE], [$group?->status, $group?->mode], 'an old card still pressed decides for the new number');
        self::assertNull($this->groups->find('telegram', '-555'));
    }

    public function testADecisionTakenBeforeTheMoveCarriesOverAndNoCardIsLeft(): void {
        $this->stack(new ScriptedLlm([]));
        $this->joined('-555', 'group');
        $this->press('gr:-555:f');
        $this->joined('-100777', 'supergroup');
        $new = $this->cardIds()[1] ?? '';

        $this->moved('-555', '-100777');

        $group = $this->groups->find('telegram', '-100777');
        self::assertSame([Group::APPROVED, Group::FREE], [$group?->status, $group?->mode]);
        self::assertContains(['42', $new], $this->stack->channel->deleted, 'the card of the new number asks what is decided already');
    }

    public function testTheCardSaysWhatTheBotNeedsToSeeTheGroupAndAFreeChoiceIsWarned(): void {
        // spec: brief/channels.md#группа — in the privacy mode a member bot gets only what is addressed to it
        $this->stack(new ScriptedLlm([]));
        $this->stack->channel->rights = new \Mercurius\Messenger\BotRights('member', false, false, false);
        $this->membership('member');

        self::assertStringContainsString('администратором', $this->cards()[0] ?? '');
        $this->press('gr:' . self::GROUP . ':f');
        self::assertStringContainsString('администратором', implode("\n", $this->stack->channel->texts('edit')), 'free, but the bot does not see the talk yet');

        $this->stack->channel->rights = new \Mercurius\Messenger\BotRights('administrator', false, true, true);
        $this->joined('-100888', 'supergroup');
        self::assertStringNotContainsString('администратором', end($this->stack->channel->log)[2] ?? '', 'an administrator sees everything');
    }

    /**
     * spec: brief/memory.md#как-сделано-области-и-факты — the words facts are looked up by are the speaker's own: not the
     * name of the group, not the last messages of others, not a forwarded text
     */
    public function testTheOwnWordsOfAGroupMessageComeWithoutTheGroupsTalk(): void {
        $this->stack(new ScriptedLlm([]));
        $this->say('первое');
        $this->press('gr:' . self::GROUP . ':m');
        $routed = $this->gate->route($this->message('@mrcuribot какой кофе пьёт владелец?', '42'));

        self::assertNotNull($routed);
        self::assertStringContainsString('Дом', $routed[1]->text, 'the agent is told the group');
        self::assertSame('какой кофе пьёт владелец?', $routed[3]);

        $id = (string) ++$this->id;
        $forwarded = new IncomingMessage('telegram', self::GROUP, '42', 'u42', 'User 42', '@mrcuribot какой кофе пьёт владелец?', $id, null, false, new DateTimeImmutable(), $id, raw: ['chat' => ['id' => (int) self::GROUP, 'title' => 'Дом', 'type' => 'supergroup'], 'forward_date' => 1]);
        $routed = $this->gate->route($forwarded);
        self::assertNotNull($routed);
        self::assertSame('', $routed[3], 'a forwarded message is someone else\'s words');
    }
}
