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
    private const BOT = '8927332395';

    private AgentStack $stack;
    private InMemoryGroupRepository $groups;
    private GroupGate $gate;
    private int $id = 100;

    private function stack(ScriptedLlm $llm): void {
        $this->stack = new AgentStack($llm);
        $this->stack->principals->ensureOwner('telegram', '42', 'Owner');
        $this->groups = new InMemoryGroupRepository();
        $this->gate = new GroupGate($this->groups, $this->stack->principals, $this->stack->channel, '42', self::BOT, 'mrcuribot', new NullLogger());
        $this->stack->useGroups($this->gate);
    }

    /** @param array<array-key, mixed> $extra */
    private function say(string $text, string $userId = '42', array $extra = [], ?string $thread = null): void {
        $id = (string) ++$this->id;
        $this->stack->chat->handle(new IncomingMessage('telegram', self::GROUP, $userId, 'u' . $userId, 'User ' . $userId, $text, $id, null, false, new DateTimeImmutable(), $id, $thread, raw: ['chat' => ['id' => (int) self::GROUP, 'title' => 'Дом', 'type' => 'supergroup']] + $extra));
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
        self::assertSame([], array_filter($this->stack->channel->texts('send'), static fn(string $t): bool => str_contains($t, 'Новый собеседник')), 'and makes no card');

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
}
