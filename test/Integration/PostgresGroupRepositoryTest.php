<?php

declare(strict_types=1);

namespace OpenCCK\Test\Integration;

use Amp\PHPUnit\AsyncTestCase;
use OpenCCK\Domain\Group\Group;
use OpenCCK\Infrastructure\Repository\PostgresGroupRepository;
use OpenCCK\Test\Support\TestDatabase;

/**
 * spec: brief/channels.md#как-сделано-группы — a group that got a new number keeps a decision, its card and the old
 * number for an old card's button; a reset asks the owner again
 *
 * @group integration
 */
final class PostgresGroupRepositoryTest extends AsyncTestCase {
    public function testAMoveKeepsADecisionOfEitherNumberAndRemembersTheOldOne(): void {
        $db = TestDatabase::connect();
        $db->query("DELETE FROM chat_groups WHERE chat_id IN ('-5551', '-1007771', '-5552', '-1007772')");
        $groups = new PostgresGroupRepository($db);

        $groups->create('telegram', '-5551', 'Дом');
        $groups->decide('telegram', '-5551', Group::APPROVED, Group::FREE);
        $groups->create('telegram', '-1007771', 'Дом');
        $groups->setCard('telegram', '-1007771', '900');
        $moved = $groups->move('telegram', '-5551', '-1007771');
        self::assertSame([Group::APPROVED, Group::FREE, '900'], [$moved?->status, $moved?->mode, $moved?->card]);
        self::assertSame('-1007771', $groups->formerly('telegram', '-5551')?->chatId);

        $groups->create('telegram', '-5552', 'Сад');
        $groups->create('telegram', '-1007772', 'Сад');
        $groups->decide('telegram', '-1007772', Group::APPROVED, Group::MENTION);
        self::assertSame(Group::MENTION, $groups->move('telegram', '-5552', '-1007772')?->mode, 'a decision on the new number stays');

        $reset = $groups->reset('telegram', '-1007772');
        self::assertSame([Group::PENDING, Group::MENTION], [$reset?->status, $reset?->mode]);
        $db->query("DELETE FROM chat_groups WHERE chat_id IN ('-1007771', '-1007772')");
        $db->close();
    }
}
