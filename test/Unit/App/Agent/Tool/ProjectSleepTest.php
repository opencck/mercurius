<?php

declare(strict_types=1);

namespace OpenCCK\Test\Unit\App\Agent\Tool;

use Amp\NullCancellation;
use Amp\PHPUnit\AsyncTestCase;
use OpenCCK\App\Agent\Tool\Projects\ProjectEditTool;
use OpenCCK\App\Agent\Tool\ToolContext;
use OpenCCK\Domain\Entity\Role;
use OpenCCK\Domain\Entity\Member;
use OpenCCK\Test\Support\AgentStack;
use OpenCCK\Test\Support\InMemoryProjectRepository;
use OpenCCK\Test\Support\InMemoryProjectSleepRepository;

use function Mercurius\I18n\t;

/**
 * Sleep of a project is off until the owner of the project or of the agent turns it on; a member below the owner of the
 * project may only ask whether it sleeps.
 *
 * spec: brief/memory.md#сон-проекта
 */
final class ProjectSleepTest extends AsyncTestCase {
    private AgentStack $stack;

    private function as(Member $who): ToolContext {
        return new ToolContext($who->id, $this->stack->conversations->openConversation($who->id, 'telegram', 'chat-' . $who->id), $who, new NullCancellation());
    }

    public function testTheOwnerTurnsSleepOfAProjectOnAndOffAndAMemberOnlyAsks(): void {
        $this->stack = new AgentStack();
        $owner = $this->stack->principals->ensureOwner('telegram', '42', 'Owner');
        $member = $this->stack->principals->createAgent('helper', Role::user());
        $projects = new InMemoryProjectRepository();
        $project = $projects->create('Новости', '');
        $projects->share($project->id, $member->id, 'write', null);
        $sleep = new InMemoryProjectSleepRepository();
        $edit = new ProjectEditTool($projects, $this->stack->conversations);
        $edit->useSleep($sleep);

        $asked = $edit->execute(['action' => 'sleep', 'project' => 'Новости'], $this->as($owner));
        self::assertSame(sprintf(t('tool.project_edit.sleep_off'), 'Новости'), $asked->content, 'off by default');

        $on = $edit->execute(['action' => 'sleep', 'project' => 'Новости', 'text' => 'on'], $this->as($owner));
        self::assertFalse($on->isError, $on->content);
        self::assertTrue($sleep->on($project->id));
        self::assertSame([$project->id], $sleep->sleeping());

        self::assertTrue($edit->execute(['action' => 'sleep', 'project' => 'Новости', 'text' => 'off'], $this->as($member))->isError, 'a writer does not decide');
        self::assertSame(sprintf(t('tool.project_edit.sleep_on'), 'Новости'), $edit->execute(['action' => 'sleep', 'project' => 'Новости'], $this->as($member))->content, 'but may ask');
        self::assertTrue($edit->execute(['action' => 'sleep', 'project' => 'Новости', 'text' => 'maybe'], $this->as($owner))->isError);

        $edit->execute(['action' => 'sleep', 'project' => 'Новости', 'text' => 'off'], $this->as($owner));
        self::assertFalse($sleep->on($project->id));
    }
}
