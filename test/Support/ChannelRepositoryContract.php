<?php

declare(strict_types=1);

namespace OpenCCK\Test\Support;

use Amp\PHPUnit\AsyncTestCase;
use DateTimeImmutable;
use OpenCCK\Domain\Group\ChannelPost;
use OpenCCK\Domain\Group\PublicChannel;
use OpenCCK\Domain\Repository\ChannelRepository;

/**
 * Behaviour every channel store must have: the in-memory fake locally, PostgreSQL on the server.
 */
abstract class ChannelRepositoryContract extends AsyncTestCase {
    protected ChannelRepository $channels;

    public function testAChannelKeepsItsRightsAndReturnsWhatWasBefore(): void {
        self::assertNull($this->channels->save(new PublicChannel('telegram', '-1002', 'Блог', true, true, false, true)));
        $before = $this->channels->save(new PublicChannel('telegram', '-1002', 'Блог 2', false, false, false, false));

        self::assertSame([true, true], [$before?->canPost, $before?->available]);
        self::assertSame(['Блог 2', false], [$this->channels->find('telegram', '-1002')?->title, $this->channels->find('telegram', '-1002')?->available]);
        $this->channels->save(new PublicChannel('telegram', '-1003', 'Афиша', true, false, false, true));
        self::assertSame(['Афиша', 'Блог 2'], array_map(static fn(PublicChannel $c): string => $c->title, $this->channels->all()));
    }

    public function testPostsAreKeptEditedAndDeleted(): void {
        $this->channels->save(new PublicChannel('telegram', '-1002', 'Блог', true, true, true, true));
        $this->channels->savePost('telegram', '-1002', '1', 'первый', new DateTimeImmutable('2026-11-17 10:00 UTC'), false);
        $this->channels->savePost('telegram', '-1002', '2', 'второй', new DateTimeImmutable('2026-11-17 11:00 UTC'), false);
        $this->channels->savePost('telegram', '-1002', '1', 'первый, исправлен', new DateTimeImmutable('2026-11-17 12:00 UTC'), true);
        $this->channels->savePost('telegram', '-9999', '1', 'чужой', new DateTimeImmutable(), false);

        self::assertSame(['второй', 'первый, исправлен'], array_map(static fn(ChannelPost $p): string => $p->text, $this->channels->posts('telegram', '-1002', 10)));
        $first = $this->channels->post('telegram', '-1002', '1');
        self::assertEquals(new DateTimeImmutable('2026-11-17 10:00 UTC'), $first?->postedAt, 'an edit keeps the time of the post');
        self::assertEquals(new DateTimeImmutable('2026-11-17 12:00 UTC'), $first?->editedAt);
        self::assertSame([], $this->channels->posts('telegram', '-9999', 10), 'posts of an unknown channel are not kept');

        $this->channels->deletePost('telegram', '-1002', '2');
        self::assertCount(1, $this->channels->posts('telegram', '-1002', 10));
    }
}
