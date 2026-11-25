<?php

declare(strict_types=1);

namespace OpenCCK\Test\Unit\App\Agent;

use Amp\NullCancellation;
use Amp\PHPUnit\AsyncTestCase;
use OpenCCK\App\Agent\ProjectContext;
use OpenCCK\App\Agent\Tool\Projects\ProjectEditTool;
use OpenCCK\App\Agent\Tool\Projects\ProjectTool;
use OpenCCK\App\Agent\Tool\ToolContext;
use OpenCCK\Domain\Agent\DangerClass;
use OpenCCK\Domain\Entity\Member;
use OpenCCK\Domain\Entity\MemberStatus;
use OpenCCK\Domain\Entity\Role;
use OpenCCK\Test\Support\InMemoryConversationRepository;
use OpenCCK\Test\Support\InMemoryMemberRepository;
use OpenCCK\Test\Support\InMemoryProjectRepository;

/**
 * spec: brief/participants.md#доступ-к-проектам-и-передача
 */
final class ProjectSharingTest extends AsyncTestCase {
    private InMemoryMemberRepository $principals;
    private InMemoryProjectRepository $projects;
    private InMemoryConversationRepository $conversations;
    private ProjectEditTool $edit;
    private ProjectTool $view;
    private Member $agent;
    private Member $user;

    protected function setUp(): void {
        parent::setUp();
        $this->principals = new InMemoryMemberRepository();
        $this->principals->ensureOwner('telegram', '42', 'Owner');
        $this->agent = $this->principals->createAgent('helper', Role::user());
        $visitor = $this->principals->createVisitor('telegram', '7', 'Анна');
        $this->user = $this->principals->decideVisitor($visitor->id, Role::user(), MemberStatus::Active) ?? self::fail('no user');
        $this->projects = new InMemoryProjectRepository();
        $this->conversations = new InMemoryConversationRepository();
        $this->edit = new ProjectEditTool($this->projects, $this->conversations, principals: $this->principals);
        $this->view = new ProjectTool($this->projects, $this->conversations);
    }

    private function as(Member $who): ToolContext {
        return new ToolContext($who->id, $this->conversations->openConversation($who->id, 'test', 'chat-' . $who->id), $who, new NullCancellation());
    }

    public function testTheOwnerOfAProjectGivesAndTakesBackAccessWithoutACard(): void {
        self::assertFalse($this->edit->execute(['action' => 'create', 'project' => 'Поиск', 'text' => 'работа'], $this->as($this->agent))->isError);
        self::assertSame(DangerClass::Write, $this->edit->danger(['action' => 'share']), 'their own project: no card of the agent owner');
        self::assertSame(DangerClass::Write, $this->edit->danger(['action' => 'unshare']));

        self::assertTrue($this->view->execute(['name' => 'Поиск'], $this->as($this->user))->isError, 'not shared yet');

        $shared = $this->edit->execute(['action' => 'share', 'project' => 'Поиск', 'participant' => 'Анна', 'access' => 'read'], $this->as($this->agent));
        self::assertFalse($shared->isError, $shared->content);
        self::assertStringContainsString('работа', $this->view->execute(['name' => 'Поиск'], $this->as($this->user))->content);
        self::assertStringContainsString('Анна', $this->edit->execute(['action' => 'members', 'project' => 'Поиск'], $this->as($this->agent))->content);

        self::assertTrue($this->edit->execute(['action' => 'unshare', 'project' => 'Поиск', 'participant' => '#' . $this->user->id], $this->as($this->agent))->isError === false);
        self::assertTrue($this->view->execute(['name' => 'Поиск'], $this->as($this->user))->isError, 'taken back');
    }

    public function testALevelSaysHowFarAMemberGoes(): void {
        $this->edit->execute(['action' => 'create', 'project' => 'Поиск', 'text' => 'работа'], $this->as($this->agent));
        $this->edit->execute(['action' => 'share', 'project' => 'Поиск', 'participant' => 'Анна', 'access' => 'read'], $this->as($this->agent));

        self::assertTrue($this->edit->execute(['action' => 'card', 'project' => 'Поиск', 'text' => 'правка'], $this->as($this->user))->isError, 'read does not change');
        self::assertTrue($this->edit->execute(['action' => 'share', 'project' => 'Поиск', 'participant' => 'owner', 'access' => 'read'], $this->as($this->user))->isError, 'read does not share');

        $this->edit->execute(['action' => 'share', 'project' => 'Поиск', 'participant' => 'Анна', 'access' => 'write'], $this->as($this->agent));
        self::assertFalse($this->edit->execute(['action' => 'card', 'project' => 'Поиск', 'text' => 'правка'], $this->as($this->user))->isError);
        self::assertTrue($this->edit->execute(['action' => 'share', 'project' => 'Поиск', 'participant' => 'owner', 'access' => 'read'], $this->as($this->user))->isError, 'write does not share');

        $this->edit->execute(['action' => 'share', 'project' => 'Поиск', 'participant' => 'Анна', 'access' => 'full'], $this->as($this->agent));
        $this->principals->createAgent('second', Role::user());
        self::assertFalse($this->edit->execute(['action' => 'share', 'project' => 'Поиск', 'participant' => 'second', 'access' => 'write'], $this->as($this->user))->isError, 'full shares on');
        self::assertTrue($this->edit->execute(['action' => 'share', 'project' => 'Поиск', 'participant' => 'owner', 'access' => 'read'], $this->as($this->user))->isError, 'the owner of the agent has every project already');
        self::assertTrue($this->edit->execute(['action' => 'unshare', 'project' => 'Поиск', 'participant' => 'helper'], $this->as($this->user))->isError, 'the owner of a project is not a member to remove');
    }

    public function testAMemberSeesTheProjectInAChatBoundToItAndMayLeave(): void {
        $this->edit->execute(['action' => 'create', 'project' => 'Поиск', 'text' => 'работа'], $this->as($this->agent));
        $project = $this->projects->byName('Поиск') ?? self::fail('no project');
        $this->projects->activate('telegram', '7', $project->id);

        self::assertSame('', (new ProjectContext($this->projects))->block($this->user, 'telegram', '7'), 'a stranger to the project gets nothing');
        $this->edit->execute(['action' => 'share', 'project' => 'Поиск', 'participant' => 'Анна', 'access' => 'read'], $this->as($this->agent));
        self::assertStringContainsString('Поиск', (new ProjectContext($this->projects))->block($this->user, 'telegram', '7'));

        self::assertFalse($this->edit->execute(['action' => 'unshare', 'project' => 'Поиск', 'participant' => 'Анна'], $this->as($this->user))->isError, 'a member leaves by themselves');
        self::assertNull($this->projects->memberLevel($project->id, $this->user->id));
    }

    public function testAParticipantBindsAndUnbindsTheirChat(): void {
        $this->edit->execute(['action' => 'create', 'project' => 'Поиск', 'text' => 'работа'], $this->as($this->agent));
        $context = $this->as($this->agent);

        self::assertFalse($this->edit->execute(['action' => 'activate', 'project' => 'Поиск'], $context)->isError);
        self::assertSame('Поиск', $this->projects->active('test', 'chat-' . $this->agent->id)?->name);
        self::assertFalse($this->edit->execute(['action' => 'deactivate'], $context)->isError);
        self::assertNull($this->projects->active('test', 'chat-' . $this->agent->id));
    }

    public function testOnlyTheOwnerOfAProjectChangesItsRightsAndMembersSeeThem(): void {
        // spec: brief/participants.md#права-проекта
        $policies = new \OpenCCK\App\Service\PolicyService(new \OpenCCK\Test\Support\InMemoryPolicyRepository(), new \OpenCCK\Domain\Agent\RunLimits(30, 1_000_000, 900, 120));
        $policies->update($this->agent, ['tools' => ['read_file', 'web_read']]);
        $this->edit->useRights(new \OpenCCK\App\Service\ProjectPolicies(
            new \OpenCCK\Test\Support\InMemoryProjectPolicyRepository(),
            $this->projects,
            $this->principals,
            $policies,
            static fn(\OpenCCK\Domain\Agent\ParticipantPolicy $p): array => array_values(array_filter(['read_file', 'web_read', 'ssh_run'], $p->allowsTool(...))),
            new \Psr\Log\NullLogger(),
        ));
        $this->edit->execute(['action' => 'create', 'project' => 'Поиск', 'text' => 'работа'], $this->as($this->agent));
        $this->edit->execute(['action' => 'share', 'project' => 'Поиск', 'participant' => 'Анна', 'access' => 'write'], $this->as($this->agent));

        self::assertTrue($this->edit->execute(['action' => 'revoke_tool', 'project' => 'Поиск', 'tool' => 'web_read'], $this->as($this->user))->isError, 'write does not change the rights');
        self::assertTrue($this->edit->execute(['action' => 'grant_tool', 'project' => 'Поиск', 'tool' => 'ssh_run'], $this->as($this->agent))->isError, 'not above the owner of the project');
        $revoked = $this->edit->execute(['action' => 'revoke_tool', 'project' => 'Поиск', 'tool' => 'web_read'], $this->as($this->agent));
        self::assertFalse($revoked->isError, $revoked->content);
        self::assertTrue($this->edit->execute(['action' => 'set_limit', 'project' => 'Поиск', 'limit' => 'max_turns', 'value' => 99], $this->as($this->agent))->isError, 'above the owner\'s 30 turns');
        self::assertFalse($this->edit->execute(['action' => 'set_limit', 'project' => 'Поиск', 'limit' => 'max_turns', 'value' => 10.0], $this->as($this->agent))->isError);

        $seen = $this->edit->execute(['action' => 'rights', 'project' => 'Поиск'], $this->as($this->user))->content;
        self::assertStringContainsString('Инструменты: read_file.', $seen);
        self::assertStringContainsString('max_turns=10', $seen);
    }
}
