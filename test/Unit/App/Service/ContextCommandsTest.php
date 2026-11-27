<?php

declare(strict_types=1);

namespace OpenCCK\Test\Unit\App\Service;

use Mercurius\Messenger\IncomingCallback;
use OpenCCK\App\Service\ContextCommands;
use OpenCCK\App\Service\ConversationPlaces;
use OpenCCK\Test\Support\FakeChannel;
use OpenCCK\Test\Support\InMemoryConversationRepository;
use OpenCCK\Test\Support\InMemoryMemberRepository;
use OpenCCK\Test\Support\InMemoryProjectRepository;
use PHPUnit\Framework\TestCase;

use function Mercurius\I18n\t;

/**
 * spec: brief/participants.md#контекст-беседы-в-личном-чате — /context shows the place and switches it, by word or button
 */
final class ContextCommandsTest extends TestCase {
    public function testContextShowsThePlaceWithButtonsAndAPressSwitchesIt(): void {
        $conversations = new InMemoryConversationRepository();
        $projects = new InMemoryProjectRepository();
        $projects->useConversations($conversations);
        $members = new InMemoryMemberRepository();
        $owner = $members->ensureOwner('telegram', '42', 'Owner');
        $shop = $projects->create('Shop', 'goal', $owner->id);
        $channel = new FakeChannel();
        $commands = new ContextCommands(new ConversationPlaces($conversations, $projects), $channel, $members);

        self::assertNull($commands->command($owner, 'telegram', '42', ''));
        $sent = $channel->texts('send');
        self::assertStringContainsString(sprintf(t('place.now'), t('place.name_personal')), (string) end($sent));
        $labels = [];
        $last = end($channel->buttons);
        self::assertIsArray($last);
        foreach ($last as $row) {
            foreach ($row as $button) {
                $labels[] = $button->text . '=' . $button->data;
            }
        }
        self::assertSame(['Личный=cx:p', '🌐 Общий=cx:g', '📁 Shop=cx:' . $shop->id], $labels);
        $context = static fn(): ?string => $conversations->meta($conversations->openConversation($owner->id, 'telegram', '42'))?->context;

        $commands->handleCallback(new IncomingCallback('telegram', 'cb1', '42', '42', null, '1', 'cx:' . $shop->id, 'u1'));
        self::assertSame('project:' . $shop->id, $context());
        self::assertStringContainsString('Shop', implode("\n", $channel->texts('edit')));

        $commands->handleCallback(new IncomingCallback('telegram', 'cb2', '42', '77', null, '1', 'cx:g', 'u2'));
        self::assertSame('project:' . $shop->id, $context(), 'someone else\'s press changes nothing');
        self::assertSame(t('place.not_yours'), end($channel->answers)[1] ?? null);

        $answer = $commands->command($owner, 'telegram', '42', 'global');
        self::assertSame(sprintf(t('place.switched'), t('place.name_global')), $answer);
    }
}
