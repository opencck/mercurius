<?php

declare(strict_types=1);

namespace OpenCCK\Test\Unit\App\Service;

use Amp\PHPUnit\AsyncTestCase;
use Mercurius\Messenger\CallbackHandler;
use Mercurius\Messenger\IncomingCallback;
use OpenCCK\App\Agent\CallbackRouter;
use OpenCCK\App\Service\OwnerDirectory;
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
}
