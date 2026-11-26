<?php

declare(strict_types=1);

namespace OpenCCK\Test\Unit\App\Agent\Tool;

use Amp\NullCancellation;
use Amp\PHPUnit\AsyncTestCase;
use Mercurius\Messenger\BotRights;
use OpenCCK\App\Agent\Tool\Messaging\GroupsTool;
use OpenCCK\App\Agent\Tool\ToolContext;
use OpenCCK\App\Service\GroupGate;
use OpenCCK\Domain\Entity\Member;
use OpenCCK\Domain\Entity\MemberKind;
use OpenCCK\Domain\Entity\Role;
use OpenCCK\Domain\Group\Group;
use OpenCCK\Test\Support\AgentStack;
use OpenCCK\Test\Support\InMemoryGroupRepository;
use OpenCCK\Test\Support\ScriptedLlm;
use Psr\Log\NullLogger;

/**
 * spec: brief/channels.md#группа — the owner sees the groups with what the bot may do there, and asks a group again:
 * the decision is forgotten and a new card comes
 */
final class GroupsToolTest extends AsyncTestCase {
    private AgentStack $stack;
    private InMemoryGroupRepository $groups;
    private GroupsTool $tool;
    private Member $owner;

    protected function setUp(): void {
        parent::setUp();
        $this->stack = new AgentStack(new ScriptedLlm([]));
        $this->owner = $this->stack->principals->ensureOwner('telegram', '42', 'Owner');
        $this->groups = new InMemoryGroupRepository();
        $gate = new GroupGate($this->groups, $this->stack->principals, $this->stack->channel, '42', '700', 'bot', new NullLogger());
        $this->tool = new GroupsTool($this->groups, $gate, $this->stack->channel);
        $this->groups->create('telegram', '-1005665676990', 'Mercurius - 368');
        $this->groups->decide('telegram', '-1005665676990', Group::APPROVED, Group::MENTION);
        $this->groups->setForum('telegram', '-1005665676990', true);
    }

    /** @param array<string, mixed> $args */
    private function call(array $args, ?Member $who = null): string {
        $who ??= $this->owner;
        return $this->tool->execute($args, new ToolContext(1, 1, $who, new NullCancellation()))->content;
    }

    public function testTheListSaysTheModeAndWhatTheBotLacks(): void {
        $this->stack->channel->rights = new BotRights('member', false, false, false);

        $list = $this->call(['action' => 'list']);

        self::assertStringContainsString('Mercurius - 368', $list);
        self::assertStringContainsString('-1005665676990', $list);
        self::assertStringContainsString('администратор', $list, 'the bot sees only what is addressed to it: it must be an administrator');
    }

    public function testAskingAgainForgetsTheDecisionAndSendsACardFoundByPartOfTheTitle(): void {
        $answer = $this->call(['action' => 'ask', 'group' => '368']);

        self::assertSame(Group::PENDING, $this->groups->find('telegram', '-1005665676990')?->status);
        self::assertNotEmpty(array_filter($this->stack->channel->texts('send'), static fn(string $t): bool => str_contains($t, 'Меня добавили в группу «Mercurius - 368»')));
        self::assertStringContainsString('Mercurius - 368', $answer);
    }

    public function testATopicIsDeletedWithTheOwnersCardAndAMissingRightIsNamed(): void {
        // spec: brief/channels.md#форум-темы--проекты — a topic is deleted on request, irreversibly, by a bot allowed to
        self::assertSame(\OpenCCK\Domain\Agent\DangerClass::Irreversible, $this->tool->danger(['action' => 'delete_topic']));
        self::assertSame('forum:delete', $this->tool->grantKey(['action' => 'delete_topic', 'group' => '368', 'topic' => '2']));
        self::assertStringContainsString('Mercurius - 368', $this->tool->describe(['action' => 'delete_topic', 'group' => '368', 'topic' => '2']));

        $answer = $this->call(['action' => 'delete_topic', 'group' => '368', 'topic' => '2']);
        self::assertSame([['delete', '-1005665676990', '2', '']], $this->stack->channel->topics);
        self::assertStringContainsString('#2', $answer);

        $this->stack->channel->topicsFail = true;
        self::assertStringContainsString('администратор', $this->call(['action' => 'delete_topic', 'group' => '368', 'topic' => '3']));
    }

    public function testOnlyAnOwnerManagesGroups(): void {
        $stranger = new Member(9, MemberKind::Human, Role::user(), 'Гость');

        self::assertStringContainsString('владел', $this->call(['action' => 'ask', 'group' => '368'], $stranger));
        self::assertSame(Group::APPROVED, $this->groups->find('telegram', '-1005665676990')?->status);
    }
}
