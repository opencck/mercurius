<?php

declare(strict_types=1);

namespace OpenCCK\Test\Unit\App\Service;

use Amp\PHPUnit\AsyncTestCase;
use Mercurius\Messenger\CallbackHandler;
use Mercurius\Messenger\IncomingCallback;
use OpenCCK\App\Agent\CallbackRouter;
use OpenCCK\App\Service\OwnerDirectory;
use OpenCCK\Domain\Entity\Member;
use OpenCCK\Domain\Entity\MemberStatus;
use OpenCCK\Domain\Entity\Role;
use OpenCCK\Test\Support\FakeChannel;
use OpenCCK\Test\Support\InMemoryMemberRepository;

/**
 * The owner from the configuration is only the first one: the directory reads the owners at the moment it is asked.
 *
 * spec: brief/participants.md#свои-роли
 */
final class OwnerDirectoryTest extends AsyncTestCase {
    public function testAnAddedOwnerLeavesTheFirstOwnerReachable(): void {
        $principals = new InMemoryMemberRepository();
        $first = $principals->ensureOwner('telegram', '100', 'Первый');
        $next = $principals->createVisitor('telegram', '200', 'Второй');
        $principals->decideVisitor($next->id, Role::user(), MemberStatus::Active);
        $owner = new OwnerDirectory($principals, 'telegram', '100');
        $channel = new FakeChannel();
        $handler = new class implements CallbackHandler {
            /** @var list<string> */
            public array $pressed = [];

            public function handleCallback(IncomingCallback $callback): void {
                $this->pressed[] = $callback->userId;
            }
        };
        $router = new CallbackRouter($channel, $owner, ['ap' => $handler]);
        $press = static fn(string $user): IncomingCallback => new IncomingCallback('telegram', 'cb', $user, $user, null, '1', 'ap:1:a', 'u');

        self::assertSame('100', $owner->chatId());
        $router->handleCallback($press('100'));

        $principals->addOwner($next->id);
        $owner->forget();

        self::assertSame('100', $owner->chatId(), 'the first owner stays the one reached first');
        self::assertSame($first->id, $owner->principal()?->id);
        $router->handleCallback($press('100'));
        self::assertSame(['100', '100'], $handler->pressed);
    }

    public function testTwoOwnersBothRecognised(): void {
        $principals = new InMemoryMemberRepository();
        $first = $principals->ensureOwner('telegram', '100', 'Первый');
        $next = $principals->createVisitor('telegram', '200', 'Второй');
        $principals->decideVisitor($next->id, Role::user(), MemberStatus::Active);
        $principals->addOwner($next->id);
        $guest = $principals->createVisitor('telegram', '300', 'Гость');
        $principals->decideVisitor($guest->id, Role::user(), MemberStatus::Active);
        $owner = new OwnerDirectory($principals, 'telegram', '100');

        self::assertSame(['100', '200'], $owner->chatIds());
        self::assertSame([$first->id, $next->id], array_map(static fn(Member $m): int => $m->id, $owner->owners()));
        self::assertTrue($owner->isOwnerAccount('200'));
        self::assertTrue($owner->isOwnerAccount('p' . $next->id), 'the id of the WebSocket API too');
        self::assertFalse($owner->isOwnerAccount('300'));
    }

    public function testSecondOwnerMayPress(): void {
        $principals = new InMemoryMemberRepository();
        $principals->ensureOwner('telegram', '100', 'Первый');
        $next = $principals->createVisitor('telegram', '200', 'Второй');
        $principals->decideVisitor($next->id, Role::user(), MemberStatus::Active);
        $principals->addOwner($next->id);
        $handler = new class implements CallbackHandler {
            /** @var list<string> */
            public array $pressed = [];

            public function handleCallback(IncomingCallback $callback): void {
                $this->pressed[] = $callback->userId;
            }
        };
        $router = new CallbackRouter(new FakeChannel(), new OwnerDirectory($principals, 'telegram', '100'), ['ap' => $handler]);

        $router->handleCallback(new IncomingCallback('telegram', 'cb', '200', '200', null, '1', 'ap:1:a', 'u'));
        $router->handleCallback(new IncomingCallback('telegram', 'cb', '300', '300', null, '1', 'ap:1:a', 'u'));

        self::assertSame(['200'], $handler->pressed);
    }


    public function testAnOwnerGivenOrTakenByARoleIsSeenAtOnce(): void {
        $principals = new InMemoryMemberRepository();
        $principals->ensureOwner('telegram', '100', 'Первый');
        $next = $principals->createVisitor('telegram', '200', 'Второй');
        $principals->decideVisitor($next->id, Role::user(), MemberStatus::Active);
        $owner = new OwnerDirectory($principals, 'telegram', '100');
        self::assertSame(['100'], $owner->chatIds());

        $principals->setRole($next->id, Role::admin(), MemberStatus::Active);
        self::assertTrue($owner->isOwnerAccount('200'), 'a new owner presses at once');

        $principals->setRole($next->id, Role::user(), MemberStatus::Active);
        self::assertFalse($owner->isOwnerAccount('200'), 'a former owner presses no more');
    }

}
