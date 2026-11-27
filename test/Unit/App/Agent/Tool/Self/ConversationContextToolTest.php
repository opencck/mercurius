<?php

declare(strict_types=1);

namespace OpenCCK\Test\Unit\App\Agent\Tool\Self;

use Amp\NullCancellation;
use OpenCCK\App\Agent\Tool\Self\ConversationContextTool;
use OpenCCK\App\Agent\Tool\ToolContext;
use OpenCCK\App\Service\ConversationPlaces;
use OpenCCK\Domain\Agent\DangerClass;
use OpenCCK\Domain\Entity\Member;
use OpenCCK\Domain\Entity\MemberKind;
use OpenCCK\Domain\Entity\Role;
use OpenCCK\Test\Support\InMemoryConversationRepository;
use OpenCCK\Test\Support\InMemoryProjectRepository;
use PHPUnit\Framework\TestCase;

use function Mercurius\I18n\t;

/**
 * spec: brief/participants.md#контекст-беседы-в-личном-чате — the agent switches the context when asked in words
 */
final class ConversationContextToolTest extends TestCase {
    public function testTheAgentShowsAndSwitchesTheContextOfThisConversation(): void {
        $conversations = new InMemoryConversationRepository();
        $projects = new InMemoryProjectRepository();
        $projects->useConversations($conversations);
        $owner = new Member(1, MemberKind::Human, Role::admin(), 'Owner');
        $projects->create('Shop', 'goal', 1);
        $tool = new ConversationContextTool(new ConversationPlaces($conversations, $projects), $conversations);
        $id = $conversations->openConversation(1, 'telegram', '42');
        $context = new ToolContext(1, $id, $owner, new NullCancellation());

        self::assertSame(DangerClass::Read, $tool->danger(['action' => 'show']));
        self::assertSame(DangerClass::Write, $tool->danger(['action' => 'switch', 'target' => 'global']));
        $shown = $tool->execute(['action' => 'show'], $context);
        self::assertStringContainsString(t('place.name_personal'), $shown->content);
        self::assertStringContainsString('Shop', $shown->content, 'the projects to switch to');

        $switched = $tool->execute(['action' => 'switch', 'target' => 'Shop'], $context);
        self::assertFalse($switched->isError, $switched->content);
        self::assertStringContainsString(t('tool.conversation_context.next'), $switched->content);
        self::assertStringStartsWith('project:', (string) $conversations->meta($id)?->context);

        $group = new ToolContext(1, $conversations->openConversation(1, 'telegram', '-100'), $owner, new NullCancellation());
        self::assertTrue($tool->execute(['action' => 'switch', 'target' => 'global'], $group)->isError);
    }
}
