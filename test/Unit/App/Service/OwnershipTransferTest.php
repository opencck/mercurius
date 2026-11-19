<?php

declare(strict_types=1);

namespace OpenCCK\Test\Unit\App\Service;

use Amp\PHPUnit\AsyncTestCase;
use Mercurius\Messenger\IncomingCallback;
use OpenCCK\App\Service\OwnerDirectory;
use OpenCCK\App\Service\OwnershipTransfer;
use OpenCCK\Domain\Entity\PrincipalStatus;
use OpenCCK\Domain\Entity\Role;
use OpenCCK\Test\Support\FakeChannel;
use OpenCCK\Test\Support\InMemoryPrincipalRepository;
use Psr\Log\NullLogger;

/**
 * /owner <number> shows a card; the press passes ownership on, the card can be cancelled and goes stale.
 *
 * spec: BRIEF.md#несколько-пользователей
 */
final class OwnershipTransferTest extends AsyncTestCase {
    private InMemoryPrincipalRepository $principals;
    private FakeChannel $channel;
    private OwnerDirectory $owner;
    private OwnershipTransfer $transfer;
    private int $now = 1_000_000;

    protected function setUp(): void {
        parent::setUp();
        $this->principals = new InMemoryPrincipalRepository();
        $this->principals->ensureOwner('telegram', '100', 'Первый');
        $next = $this->principals->createVisitor('telegram', '200', 'Второй');
        $this->principals->decideVisitor($next->id, Role::user(), PrincipalStatus::Active);
        $this->channel = new FakeChannel();
        $this->owner = new OwnerDirectory($this->principals, 'telegram', '100');
        $this->transfer = new OwnershipTransfer($this->principals, $this->channel, $this->owner, new NullLogger(), fn(): int => $this->now);
    }

    /** The data of the last button of the given text on the card. */
    private function button(string $label): string {
        $last = end($this->channel->buttons);
        foreach ($last === false ? [] : $last as $row) {
            foreach ($row as $button) {
                if ($button->text === $label) {
                    return $button->data;
                }
            }
        }
        self::fail('no button ' . $label);
    }

    private function press(string $data): void {
        $this->transfer->handleCallback(new IncomingCallback('telegram', 'cb', '100', '100', null, '7', $data, 'u'));
    }

    public function testThePressPassesOwnershipAndTheNewOwnerIsTold(): void {
        $first = $this->owner->principal();
        self::assertNotNull($first);
        self::assertSame(\Mercurius\I18n\t('ownership.usage'), $this->transfer->ask($first, '100', ''));
        self::assertSame(\Mercurius\I18n\t('ownership.not_eligible'), $this->transfer->ask($first, '100', (string) $first->id));
        self::assertNull($this->transfer->ask($first, '100', '2'));

        $this->press($this->button(\Mercurius\I18n\t('ownership.confirm')));

        self::assertSame(2, $this->principals->owner()?->id);
        self::assertSame(Role::admin(), $this->principals->find($first->id)?->role);
        self::assertSame('200', $this->owner->chatId(), 'the directory reads the new owner');
        self::assertContains(\Mercurius\I18n\t('ownership.welcome'), $this->channel->texts('send'));
    }

    public function testTheCommandIsTheOwnersAndOnlyInTheirPrivateChat(): void {
        $stack = new \OpenCCK\Test\Support\AgentStack();
        $owner = $stack->principals->ensureOwner('telegram', '42', 'Владелец');
        $directory = new OwnerDirectory($stack->principals, 'telegram', '42');
        $stack->chat->useOwnership(new OwnershipTransfer($stack->principals, $stack->channel, $directory, new NullLogger()));

        $stack->sayInGroup('/owner 2');
        $stack->say('/owner');

        $sent = $stack->channel->texts('send');
        self::assertContains(\Mercurius\I18n\t(\OpenCCK\App\Service\ChatService::PRIVATE_ONLY), $sent);
        self::assertContains(\Mercurius\I18n\t('ownership.usage'), $sent);
        self::assertSame($owner->id, $stack->principals->owner()?->id);
    }

    public function testACancelledOrStaleCardPassesNothing(): void {
        $first = $this->owner->principal();
        self::assertNotNull($first);
        $this->transfer->ask($first, '100', '2');
        $this->press($this->button(\Mercurius\I18n\t('ownership.cancel')));
        $this->transfer->ask($first, '100', '2');
        $confirm = $this->button(\Mercurius\I18n\t('ownership.confirm'));
        $this->now += 601;
        $this->press($confirm);

        self::assertSame($first->id, $this->principals->owner()?->id);
        self::assertSame([\Mercurius\I18n\t('ownership.cancelled'), \Mercurius\I18n\t('ownership.expired')], array_column($this->channel->answers, 1));
    }
}
