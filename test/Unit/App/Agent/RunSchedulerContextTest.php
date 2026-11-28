<?php

declare(strict_types=1);

namespace OpenCCK\Test\Unit\App\Agent;

use Amp\PHPUnit\AsyncTestCase;
use OpenCCK\App\Service\ConversationPlaces;
use OpenCCK\Test\Support\AgentStack;
use OpenCCK\Test\Support\InMemoryProjectRepository;
use OpenCCK\Test\Support\ScriptedLlm;

/**
 * spec: brief/participants.md#контекст-беседы-в-личном-чате — a run works in the context of its conversation
 */
final class RunSchedulerContextTest extends AsyncTestCase {
    public function testARunInTheSharedContextGetsTheOwnersConnectionsAndAMark(): void {
        $stack = new AgentStack(new ScriptedLlm([ScriptedLlm::text('Привет!')]));
        $projects = new InMemoryProjectRepository();
        $projects->useConversations($stack->conversations);
        $places = new ConversationPlaces($stack->conversations, $projects);
        $stack->scheduler->useConversationPlaces($places);
        $owner = $stack->principals->ensureOwner('telegram', '42', 'Owner');
        self::assertTrue($places->switchTo($owner, 'telegram', '42', 'global')[0]);

        $stack->say('привет');
        $stack->scheduler->awaitIdle();

        $id = $stack->conversations->openConversation($owner->id, 'telegram', '42');
        self::assertSame('owners', $stack->scheduler->policyFor($id, $owner)->mcpContext);
        $edits = $stack->channel->texts('edit');
        self::assertSame("🌐 global\n\nПривет!", end($edits));
    }

    public function testARunInAProjectWorksInTheFolderOfTheProjectAndTheOwnersContextInTheRoot(): void {
        // spec: brief/agent-loop.md#папка-контекста — the folder of a run follows its context
        $stack = new AgentStack(new ScriptedLlm([ScriptedLlm::text('ok'), ScriptedLlm::text('ok')]));
        $projects = new InMemoryProjectRepository();
        $projects->useConversations($stack->conversations);
        $places = new ConversationPlaces($stack->conversations, $projects);
        $stack->scheduler->useConversationPlaces($places);
        $stack->scheduler->useProjectPolicies(new \OpenCCK\App\Service\ProjectPolicies(new \OpenCCK\Test\Support\InMemoryProjectPolicyRepository(), $projects, $stack->principals, $stack->policies, $stack->registry->names(...), new \Psr\Log\NullLogger()));
        $stack->scheduler->useProjects(new \OpenCCK\App\Agent\ProjectContext($projects));
        $stack->scheduler->useSandboxHolders(new \OpenCCK\App\Service\SandboxHolders(static fn(): int => 1));
        $owner = $stack->principals->ensureOwner('telegram', '42', 'Owner');
        $shop = $projects->create('Shop', 'goal');
        $id = $stack->conversations->openConversation($owner->id, 'telegram', '42');

        $places->switchTo($owner, 'telegram', '42', 'Shop');
        self::assertSame(\OpenCCK\Sandbox\Workspace::projectHolder($shop->id, 1), $stack->scheduler->policyFor($id, $owner)->holder, 'the project of the owner of the agent lies in the owner\'s folder');
        $places->switchTo($owner, 'telegram', '42', 'global');
        self::assertSame(0, $stack->scheduler->policyFor($id, $owner)->holder);
        $places->switchTo($owner, 'telegram', '42', 'personal');
        self::assertSame($owner->id, $stack->scheduler->policyFor($id, $owner)->holder);
    }

    public function testALostContextIsSaidInTheAnswer(): void {
        $stack = new AgentStack(new ScriptedLlm([ScriptedLlm::text('Привет!')]));
        $projects = new InMemoryProjectRepository();
        $projects->useConversations($stack->conversations);
        $stack->scheduler->useConversationPlaces(new ConversationPlaces($stack->conversations, $projects));
        $owner = $stack->principals->ensureOwner('telegram', '42', 'Owner');
        $id = $stack->conversations->openConversation($owner->id, 'telegram', '42');
        $stack->conversations->setContext($id, 'project:999');

        $stack->say('привет');
        $stack->scheduler->awaitIdle();

        $edits = $stack->channel->texts('edit');
        self::assertSame(\Mercurius\I18n\t('place.lost_project') . "\n\nПривет!", end($edits));
        self::assertSame('member:' . $owner->id, $stack->scheduler->policyFor($id, $owner)->mcpContext);
    }
}
