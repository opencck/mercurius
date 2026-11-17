<?php

declare(strict_types=1);

namespace OpenCCK\Test\Unit\App\Agent\Tool;

use Amp\NullCancellation;
use Amp\PHPUnit\AsyncTestCase;
use OpenCCK\App\Agent\Tool\ForumTopicTool;
use OpenCCK\App\Agent\Tool\ToolContext;
use OpenCCK\Domain\Agent\DangerClass;
use OpenCCK\Domain\Entity\Principal;
use OpenCCK\Domain\Entity\PrincipalKind;
use OpenCCK\Domain\Entity\Role;
use OpenCCK\Test\Support\FakeChannel;
use OpenCCK\Test\Support\InMemoryConversationRepository;

/**
 * The agent changes the forum topic it talks in; deleting a topic erases its messages and waits for the owner.
 */
final class ForumTopicToolTest extends AsyncTestCase {
    public function testTheTopicOfTheConversationIsChangedAndDeletionIsIrreversible(): void {
        $channel = new FakeChannel();
        $conversations = new InMemoryConversationRepository();
        $topic = $conversations->openConversation(1, 'telegram', '-100500', '77');
        $general = $conversations->openConversation(1, 'telegram', '-100500');
        $tool = new ForumTopicTool($channel, $conversations);
        $owner = new Principal(1, PrincipalKind::Human, Role::Owner, null);
        $in = static fn(int $conversation): ToolContext => new ToolContext(1, $conversation, $owner, new NullCancellation());

        self::assertSame(DangerClass::Irreversible, $tool->danger(['action' => 'delete']));
        self::assertSame(DangerClass::Write, $tool->danger(['action' => 'close']));
        self::assertFalse($tool->execute(['action' => 'rename', 'name' => 'Сайт 2'], $in($topic))->isError);
        self::assertFalse($tool->execute(['action' => 'close'], $in($topic))->isError);
        self::assertFalse($tool->execute(['action' => 'delete'], $in($topic))->isError);
        self::assertTrue($tool->execute(['action' => 'close'], $in($general))->isError, 'General is not a topic to close');
        self::assertTrue($tool->execute(['action' => 'rename'], $in($topic))->isError, 'a rename needs a name');

        self::assertSame([['edit', '-100500', '77', 'Сайт 2'], ['close', '-100500', '77', ''], ['delete', '-100500', '77', '']], $channel->topics);
    }
}
