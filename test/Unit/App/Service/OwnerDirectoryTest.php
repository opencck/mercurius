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
 * The owner from the configuration is only the first one: after ownership passed on, cards go to the new owner and
 * only the new owner presses.
 *
 * spec: brief/participants.md#несколько-пользователей
 */
final class OwnerDirectoryTest extends AsyncTestCase {
    public function testAfterATransferTheOwnerIsReachedAndHeardInTheirOwnChat(): void {
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

        $principals->transferOwnership($first->id, $next->id);
        $owner->forget();

        self::assertSame('200', $owner->chatId());
        self::assertSame($next->id, $owner->principal()?->id);
        $router->handleCallback($press('100'));
        $router->handleCallback($press('200'));
        self::assertSame(['100', '200'], $handler->pressed, 'the former owner presses no more');
    }
}
