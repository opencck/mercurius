<?php

declare(strict_types=1);

namespace OpenCCK\Test\Unit\App\Service;

use Amp\PHPUnit\AsyncTestCase;
use DateTimeImmutable;
use Mercurius\Messenger\IncomingMembership;
use Mercurius\Messenger\IncomingPost;
use Mercurius\Messenger\MembershipHandler;
use OpenCCK\App\Service\ChannelRegistry;
use OpenCCK\App\Service\MembershipRouter;
use OpenCCK\Test\Support\FakeChannel;
use OpenCCK\Test\Support\InMemoryChannelRepository;
use Psr\Log\NullLogger;

/**
 * The bot learns its rights in a channel from Telegram and keeps what the channel published.
 */
final class ChannelRegistryTest extends AsyncTestCase {
    private FakeChannel $chat;
    private InMemoryChannelRepository $channels;
    private ChannelRegistry $registry;
    private int $update = 0;

    protected function setUp(): void {
        parent::setUp();
        $this->chat = new FakeChannel();
        $this->channels = new InMemoryChannelRepository();
        $this->registry = new ChannelRegistry($this->channels, $this->chat, '42', new NullLogger());
    }

    /** @param array<string, bool> $rights */
    private function member(string $status, array $rights = [], string $type = 'channel'): void {
        $this->registry->handleMembership(new IncomingMembership('telegram', '-1002', 'Блог', $type, '42', 'Owner', $status, 'left', (string) ++$this->update, $rights));
    }

    public function testAnAdminWithTheRightToPostMakesTheChannelAvailableAndTheOwnerIsTold(): void {
        $this->member('administrator', ['can_post_messages' => true, 'can_edit_messages' => true, 'can_delete_messages' => false]);

        $channel = $this->channels->find('telegram', '-1002');
        self::assertSame([true, true, true, false], [$channel?->available, $channel?->canPost, $channel?->canEdit, $channel?->canDelete]);
        self::assertStringContainsString('администратор канала «Блог»', implode("\n", $this->chat->texts('send')));
        self::assertStringContainsString('публикация, правка', implode("\n", $this->chat->texts('send')));

        $this->member('administrator', ['can_post_messages' => true, 'can_edit_messages' => true, 'can_delete_messages' => false]);
        self::assertCount(1, $this->chat->texts('send'), 'the same rights again — no message');

        $this->member('left');
        self::assertFalse($this->channels->find('telegram', '-1002')?->available);
        self::assertStringContainsString('больше не могу публиковать в канале «Блог»', implode("\n", $this->chat->texts('send')));
    }

    public function testAnAdminWithoutTheRightToPostIsNotAvailableAndGroupsAreNotChannels(): void {
        $this->member('administrator', ['can_post_messages' => false]);
        self::assertFalse($this->channels->find('telegram', '-1002')?->available);

        $this->registry->handleMembership(new IncomingMembership('telegram', '-100500', 'Дом', 'supergroup', '42', 'Owner', 'member', 'left', '99'));
        self::assertNull($this->channels->find('telegram', '-100500'));
    }

    public function testPostsOfTheChannelAreKept(): void {
        $this->member('creator');
        $this->registry->handlePost(new IncomingPost('telegram', '-1002', 'Блог', '31', 'Новый пост', new DateTimeImmutable('2026-11-17 10:00 UTC'), false, '1'));
        $this->registry->handlePost(new IncomingPost('telegram', '-1002', 'Блог', '31', 'Исправленный пост', new DateTimeImmutable('2026-11-17 11:00 UTC'), true, '2'));

        self::assertSame('Исправленный пост', $this->channels->post('telegram', '-1002', '31')?->text);
        self::assertTrue($this->channels->find('telegram', '-1002')?->canDelete, 'the creator may do everything');
    }

    public function testTheRouterHandsAChangeToEveryHandler(): void {
        $seen = new class implements MembershipHandler {
            public int $count = 0;

            public function handleMembership(IncomingMembership $change): void {
                $this->count++;
            }
        };
        (new MembershipRouter([$seen, $this->registry]))->handleMembership(new IncomingMembership('telegram', '-1002', 'Блог', 'channel', '42', null, 'creator', null, '7'));

        self::assertSame(1, $seen->count);
        self::assertNotNull($this->channels->find('telegram', '-1002'));
    }
}
