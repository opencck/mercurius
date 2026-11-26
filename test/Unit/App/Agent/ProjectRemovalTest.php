<?php

declare(strict_types=1);

namespace OpenCCK\Test\Unit\App\Agent;

use Amp\NullCancellation;
use Amp\PHPUnit\AsyncTestCase;
use OpenCCK\App\Agent\Tool\Projects\ProjectEditTool;
use OpenCCK\App\Agent\Tool\ToolContext;
use OpenCCK\App\Service\ForumTopics;
use OpenCCK\App\Service\ProjectFolders;
use OpenCCK\Domain\Agent\DangerClass;
use OpenCCK\Domain\Agent\SandboxLimits;
use OpenCCK\Domain\Entity\Member;
use OpenCCK\Domain\Entity\MemberKind;
use OpenCCK\Domain\Entity\Role;
use OpenCCK\Domain\Group\Group;
use OpenCCK\Test\Support\FakeChannel;
use OpenCCK\Test\Support\FakeSandbox;
use OpenCCK\Test\Support\InMemoryConversationRepository;
use OpenCCK\Test\Support\InMemoryGroupRepository;
use OpenCCK\Test\Support\InMemoryProjectRepository;
use Psr\Log\NullLogger;

/**
 * spec: brief/participants.md#удаление-проекта — a project is deleted with the owner's card (and a grant for good, as any
 * irreversible action): its record with what hangs on it, its folder, its MCP entries; its forum topics only when asked
 */
final class ProjectRemovalTest extends AsyncTestCase {
    private InMemoryProjectRepository $projects;
    private InMemoryConversationRepository $conversations;
    private FakeChannel $channel;
    private FakeSandbox $sandbox;
    private ProjectEditTool $tool;
    private Member $owner;
    /** @var list<string> */
    private array $forgotten = [];

    protected function setUp(): void {
        parent::setUp();
        $this->projects = new InMemoryProjectRepository();
        $this->conversations = new InMemoryConversationRepository();
        $this->channel = new FakeChannel();
        $this->sandbox = new FakeSandbox();
        $groups = new InMemoryGroupRepository();
        $groups->create('telegram', '-100500', 'Дом');
        $groups->decide('telegram', '-100500', Group::APPROVED, Group::MENTION);
        $groups->setForum('telegram', '-100500', true);
        $this->owner = new Member(1, MemberKind::Human, Role::admin(), null);
        $this->tool = new ProjectEditTool($this->projects, $this->conversations, forum: new ForumTopics($this->channel, $groups, $this->projects, new NullLogger()));
        $this->tool->useRemoval(
            new ProjectFolders($this->sandbox, static fn(int $id): SandboxLimits => new SandboxLimits(1_000_000, 10, 10, 1_000_000, 1)),
            static fn(): int => 1,
            function (int $projectId): void {
                $this->forgotten[] = 'project:' . $projectId;
            },
        );
    }

    private function call(string $text, bool $topics = false): \OpenCCK\App\Agent\Tool\ToolResult {
        $conversation = $this->conversations->openConversation(1, 'telegram', '-100500', '500');
        $args = ['action' => 'delete', 'project' => 'Сайт'] + ($topics ? ['topics' => true] : []);
        return $this->tool->execute($args, new ToolContext(1, $conversation, $this->owner, new NullCancellation(), approved: true));
    }

    private function project(): int {
        $project = $this->projects->create('Сайт', 'Магазин');
        $this->projects->activate('telegram', '-100500', $project->id, '500');
        return $project->id;
    }

    public function testDeletingIsIrreversibleAndMayBeAllowedForGood(): void {
        self::assertSame(DangerClass::Irreversible, $this->tool->danger(['action' => 'delete', 'project' => 'Сайт']));
        self::assertSame('project:delete', $this->tool->grantKey(['action' => 'delete', 'project' => 'Сайт']), 'a grant for good covers any project: a deleted one is gone');
        $this->project();
        self::assertStringContainsString('projects/', $this->tool->describe(['action' => 'delete', 'project' => 'Сайт']), 'the card says the folder goes');
    }

    public function testTheProjectItsFolderAndItsEntriesGoButItsTopicsStayUnlessAsked(): void {
        $id = $this->project();

        $result = $this->call('удали проект');

        self::assertFalse($result->isError, $result->content);
        self::assertNull($this->projects->find($id));
        self::assertSame([], $this->projects->topicsOf($id));
        self::assertNotEmpty(array_filter($this->sandbox->calls, static fn(string $c): bool => str_contains($c, "rm -rf 'projects/" . $id . "'")));
        self::assertSame(['project:' . $id], $this->forgotten);
        self::assertSame([], $this->channel->topics, 'the topic stays');
        self::assertStringContainsString('остались', $result->content, 'the answer says the topic stays and how to delete it');
    }

    public function testTopicsAreDeletedWhenAskedAndAFailureNamesTheRightsNeeded(): void {
        $this->project();
        $this->call('', topics: true);
        self::assertSame([['delete', '-100500', '500', '']], $this->channel->topics);

        $this->channel->topicsFail = true;
        $this->project();
        $result = $this->call('', topics: true);
        self::assertStringContainsString('администратор', $result->content);
    }

    public function testTheAgentOpensTheOwnersSecretsToTheProjectWithTheOwnersCard(): void {
        // spec: brief/skills-mcp.md#подстановка-секретов — the project owner shares their own secrets with the project
        $inherit = new \OpenCCK\Test\Support\InMemorySecretInheritanceRepository();
        $this->tool->useInheritance($inherit, static fn(): int => 1);
        $id = $this->project();
        self::assertSame(DangerClass::Irreversible, $this->tool->danger(['action' => 'inherit', 'project' => 'Сайт', 'layer' => 'owner', 'on' => true]));
        self::assertSame(DangerClass::Write, $this->tool->danger(['action' => 'inherit', 'project' => 'Сайт']), 'the state is only read');

        $result = $this->tool->execute(['action' => 'inherit', 'project' => 'Сайт', 'layer' => 'owner', 'on' => true], new ToolContext(1, 1, $this->owner, new NullCancellation(), approved: true));

        self::assertFalse($result->isError, $result->content);
        self::assertSame(['owner' => true, 'agent' => false, 'by' => 1], $inherit->of('project:' . $id));
        $stranger = new Member(5, MemberKind::Human, Role::user(), 'Коля');
        $this->projects->share($id, 5, 'full', 1);
        self::assertTrue($this->tool->execute(['action' => 'inherit', 'project' => 'Сайт', 'layer' => 'agent', 'on' => true], new ToolContext(1, 1, $stranger, new NullCancellation()))->isError, 'the agent\'s secrets — only an owner of the agent');
    }

    public function testOnlyTheOwnerOfTheProjectDeletesIt(): void {
        $this->project();
        $member = new Member(5, MemberKind::Human, Role::user(), 'Коля');
        $this->projects->share($this->projects->byName('Сайт')->id ?? 0, 5, 'full', 1);

        $result = $this->tool->execute(['action' => 'delete', 'project' => 'Сайт'], new ToolContext(1, $this->conversations->openConversation(5, 'telegram', '5'), $member, new NullCancellation()));

        self::assertTrue($result->isError);
        self::assertNotNull($this->projects->byName('Сайт'));
    }
}
