<?php

declare(strict_types=1);

namespace OpenCCK\Test\Unit\App\Agent;

use Amp\PHPUnit\AsyncTestCase;
use OpenCCK\App\Agent\BanList;
use OpenCCK\Domain\Entity\MemberStatus;
use OpenCCK\Domain\Entity\Role;
use OpenCCK\Test\Support\AgentStack;
use OpenCCK\Test\Support\InMemoryProjectRepository;
use OpenCCK\Test\Support\ScriptedLlm;
use Psr\Log\NullLogger;

/**
 * Bans by command: who sets and lifts which.
 *
 * spec: brief/agent-loop.md#запреты
 */
final class BanListTest extends AsyncTestCase {
    public function testTheOwnerOfAProjectBansInTheirProjectAndDoesNotLiftTheBanOfTheAgentsOwner(): void {
        $stack = new AgentStack(new ScriptedLlm([]));
        $owner = $stack->principals->ensureOwner('telegram', '42', 'Owner');
        $anna = $stack->principals->decideVisitor($stack->principals->createVisitor('telegram', '7', 'Анна')->id, Role::user(), MemberStatus::Active);
        self::assertNotNull($anna);
        $projects = new InMemoryProjectRepository();
        $project = $projects->create('Сайт', '', $anna->id);
        $bans = new BanList($stack->approvals, $stack->principals, $stack->channel, new NullLogger(), $projects);
        $last = static fn(): string => array_slice($stack->channel->texts('send'), -1)[0] ?? '';

        $bans->command($anna, 'all tool push', '7', null);
        self::assertStringContainsString('только в своём проекте', $last());
        $bans->command($anna, 'project ' . $project->id . ' server prod', '7', null);
        self::assertStringContainsString('команды на сервере prod', $last());
        $bans->command($owner, 'project ' . $project->id . ' tool push', '42', null);
        self::assertCount(2, $stack->approvals->bans());

        $bans->command($anna, 'remove 2', '7', null);
        self::assertStringContainsString('снять нельзя', $last(), 'the ban of the owner of the agent stays');
        $bans->command($anna, 'remove 1', '7', null);
        self::assertStringContainsString('снят', $last());
        self::assertSame(['tool:push'], array_map(static fn(\OpenCCK\Domain\Agent\Ban $b): string => $b->key, $stack->approvals->bans()));

        $bans->command($owner, '', '42', null);
        self::assertStringContainsString('в проекте «Сайт»', $last());
        self::assertSame(['bn:x:2'], array_map(static fn(\Mercurius\Messenger\Button $b): string => $b->data, array_merge(...(array_slice($stack->channel->buttons, -1)[0] ?? []))));
    }
}
