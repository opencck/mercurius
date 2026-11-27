<?php

declare(strict_types=1);

namespace OpenCCK\Test\Unit\App\Service;

use Amp\PHPUnit\AsyncTestCase;
use OpenCCK\App\Agent\Sandbox\CommandOutcome;
use OpenCCK\App\Service\ContextCommands;
use OpenCCK\App\Service\ConversationPlaces;
use OpenCCK\App\Service\FolderTree;
use OpenCCK\Domain\Agent\SandboxLimits;
use OpenCCK\Test\Support\AgentStack;
use OpenCCK\Test\Support\FakeSandbox;
use OpenCCK\Test\Support\InMemoryProjectRepository;
use OpenCCK\Test\Support\ScriptedLlm;

use function Mercurius\I18n\t;

/**
 * spec: brief/participants.md#контекст-беседы-в-личном-чате — /context switches, /new brings back the own context,
 * /ls shows the folder of the context
 */
final class ChatContextCommandsTest extends AsyncTestCase {
    public function testContextNewAndLsInAPrivateChat(): void {
        $stack = new AgentStack(new ScriptedLlm());
        $projects = new InMemoryProjectRepository();
        $projects->useConversations($stack->conversations);
        $places = new ConversationPlaces($stack->conversations, $projects);
        $sandbox = new FakeSandbox();
        $sandbox->outcome = new CommandOutcome(0, "4096\t./a.md\n4096\t.\n" . FolderTree::DIRS . "\n", '', false, false, 1);
        $stack->chat->useContexts(new ContextCommands($places, $stack->channel, $stack->principals), $places, new FolderTree($sandbox, $projects, static fn(): int => 1), static fn(\OpenCCK\Domain\Entity\Member $who): SandboxLimits => new SandboxLimits(100, 10, 5, 1000, 1));
        $owner = $stack->principals->ensureOwner('telegram', '42', 'Owner');

        $stack->say('/context global');
        self::assertSame('owners', $stack->conversations->meta($stack->conversations->openConversation($owner->id, 'telegram', '42'))?->context);

        $stack->say('/ls 5');
        $sent = $stack->channel->texts('send');
        self::assertStringContainsString(sprintf(t('ls.context'), t('place.name_global')), (string) end($sent));
        self::assertStringContainsString('└─ a.md — 4 КБ', (string) end($sent));
        self::assertStringStartsWith('exec 0 ', $sandbox->calls[0]);
        self::assertStringContainsString('--max-depth=3', $sandbox->calls[0], 'deeper than 3 is 3');

        $stack->say('/new');
        self::assertNull($stack->conversations->meta($stack->conversations->openConversation($owner->id, 'telegram', '42'))?->context);

        $stack->sayInGroup('/context global');
        $sent = $stack->channel->texts('send');
        self::assertSame(t('place.private_only'), end($sent));
    }
}
