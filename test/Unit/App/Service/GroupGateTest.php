<?php

declare(strict_types=1);

namespace OpenCCK\Test\Unit\App\Service;

use Amp\PHPUnit\AsyncTestCase;
use DateTimeImmutable;
use Mercurius\Messenger\IncomingCallback;
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
    private function say(string $text, string $userId = '42', array $extra = []): void {
        $id = (string) ++$this->id;
        $this->stack->chat->handle(new IncomingMessage('telegram', self::GROUP, $userId, 'u' . $userId, 'User ' . $userId, $text, $id, null, false, new DateTimeImmutable(), $id, raw: ['chat' => ['id' => (int) self::GROUP, 'title' => 'Дом', 'type' => 'supergroup']] + $extra));
        delay(0.02);
    }

    private function press(string $data): void {
        $this->gate->handleCallback(new IncomingCallback('telegram', 'cb' . ++$this->id, '42', '42', 'Owner', '7', $data, (string) $this->id));
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
        self::assertStringContainsString('[Группа «Дом», пишет User 42.]', $prompt);
        self::assertStringContainsString('кто оплатит свет? там 1200', $prompt, 'the talk of the group comes as data');
        self::assertStringContainsString('<untrusted-data source="group-chat">', $prompt);
        self::assertStringEndsWith("\n\nсколько за свет?", $prompt, 'the mention is dropped');
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
