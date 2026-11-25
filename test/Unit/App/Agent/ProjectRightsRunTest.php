<?php

declare(strict_types=1);

namespace OpenCCK\Test\Unit\App\Agent;

use Amp\PHPUnit\AsyncTestCase;
use OpenCCK\App\Agent\ProjectContext;
use OpenCCK\App\Service\ProjectPolicies;
use OpenCCK\Domain\Agent\ParticipantPolicy;
use OpenCCK\Domain\Entity\MemberStatus;
use OpenCCK\Domain\Entity\Role;
use OpenCCK\Test\Support\AgentStack;
use OpenCCK\Test\Support\FakeTool;
use OpenCCK\Test\Support\InMemoryProjectPolicyRepository;
use OpenCCK\Test\Support\InMemoryProjectRepository;
use OpenCCK\Test\Support\ScriptedLlm;
use Psr\Log\NullLogger;

/**
 * A run in a project's chat works with the project's rights, whoever of its members writes.
 *
 * spec: brief/participants.md#права-проекта
 */
final class ProjectRightsRunTest extends AsyncTestCase {
    /** @return list<string> */
    private static function tools(ScriptedLlm $llm, int $request): array {
        return array_map(static fn(\Mercurius\Llm\ToolDefinition $d): string => $d->name, $llm->requests[$request]->tools);
    }

    public function testAMemberWithFewerRightsWorksInTheProjectWithTheProjectsTools(): void {
        $llm = new ScriptedLlm([ScriptedLlm::text('в проекте'), ScriptedLlm::text('вне проекта')]);
        $stack = new AgentStack($llm);
        $stack->principals->ensureOwner('telegram', '42', 'Owner');
        foreach (['read_file', 'web_read', 'ssh_run'] as $name) {
            $stack->registry->register(new FakeTool($name));
        }
        $alice = $stack->principals->decideVisitor($stack->principals->createVisitor('telegram', '51', 'Алиса')->id, Role::user(), MemberStatus::Active);
        $bob = $stack->principals->decideVisitor($stack->principals->createVisitor('telegram', '52', 'Боб')->id, Role::user(), MemberStatus::Active);
        self::assertNotNull($alice);
        self::assertNotNull($bob);
        $stack->policies->update($alice, ['tools' => ['read_file', 'web_read', 'ssh_run']]);
        $stack->policies->update($bob, ['tools' => ['read_file']]);
        $projects = new InMemoryProjectRepository();
        $project = $projects->create('Новости', '', $alice->id);
        $projects->share($project->id, $bob->id, 'write', $alice->id);
        $rights = new ProjectPolicies(new InMemoryProjectPolicyRepository(), $projects, $stack->principals, $stack->policies, $stack->registry->names(...), new NullLogger());
        $rights->revoke($project, 'ssh_run');
        $stack->scheduler->useProjects(new ProjectContext($projects));
        $stack->scheduler->useProjectPolicies($rights);
        $projects->activate('telegram', '52', $project->id);

        $stack->say('собери новости', '52');
        $stack->scheduler->awaitIdle();
        $projects->activate('telegram', '52', null);
        $stack->say('а теперь вне проекта', '52');
        $stack->scheduler->awaitIdle();

        self::assertEqualsCanonicalizing(['read_file', 'web_read'], array_values(array_intersect(self::tools($llm, 0), ['read_file', 'web_read', 'ssh_run'])), 'the tools the owner of the project set');
        self::assertEqualsCanonicalizing(['read_file'], array_values(array_intersect(self::tools($llm, 1), ['read_file', 'web_read', 'ssh_run'])), 'outside the project — Bob\'s own');
    }

    /** spec: brief/skills-mcp.md#процессы-и-реестр-инструментов-по-контекстам — the context of a run */
    public function testARunGetsTheConnectionsOfItsContext(): void {
        $llm = new ScriptedLlm([ScriptedLlm::text('в проекте'), ScriptedLlm::text('в личке'), ScriptedLlm::text('в группе')]);
        $stack = new AgentStack($llm);
        $stack->principals->ensureOwner('telegram', '42', 'Owner');
        $stack->registry->register(new FakeTool('read_file'));
        $alice = $stack->principals->decideVisitor($stack->principals->createVisitor('telegram', '51', 'Алиса')->id, Role::user(), MemberStatus::Active);
        self::assertNotNull($alice);
        $stack->policies->update($alice, ['tools' => ['read_file']]);
        $projects = new InMemoryProjectRepository();
        $project = $projects->create('Новости', '', $alice->id);
        $rights = new ProjectPolicies(new InMemoryProjectPolicyRepository(), $projects, $stack->principals, $stack->policies, $stack->registry->names(...), new NullLogger());
        $stack->scheduler->useProjects(new ProjectContext($projects));
        $stack->scheduler->useProjectPolicies($rights);
        $stack->registry->registerIn('project:' . $project->id, new FakeTool('mcp_docs_search'));
        $stack->registry->registerIn('member:' . $alice->id, new FakeTool('mcp_mine_search'));

        $projects->activate('telegram', '51', $project->id);
        $stack->say('в проекте', '51');
        $stack->scheduler->awaitIdle();
        $projects->activate('telegram', '51', null);
        $stack->say('в личке', '51');
        $stack->scheduler->awaitIdle();

        self::assertContains('mcp_docs_search', self::tools($llm, 0), 'the connection of the project, though not on the list of the member');
        self::assertNotContains('mcp_mine_search', self::tools($llm, 0));
        self::assertContains('mcp_mine_search', self::tools($llm, 1), 'the private chat: the own connection of the member');
        self::assertNotContains('mcp_docs_search', self::tools($llm, 1));
    }

    public function testRightsOfAnEmptyPolicyStayEmpty(): void {
        $policy = new ParticipantPolicy([], new \OpenCCK\Domain\Agent\RunLimits(1, 1, 1, 1), null, false);

        self::assertTrue($policy->readOnlyCopy()->readOnly);
        self::assertSame([], $policy->readOnlyCopy()->tools);
    }
}
