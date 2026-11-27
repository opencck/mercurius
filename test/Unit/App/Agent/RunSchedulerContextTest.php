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
