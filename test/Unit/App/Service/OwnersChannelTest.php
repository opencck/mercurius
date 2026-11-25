<?php

declare(strict_types=1);

namespace OpenCCK\Test\Unit\App\Service;

use Amp\PHPUnit\AsyncTestCase;
use Mercurius\Messenger\Button;
use Mercurius\Messenger\CallbackHandler;
use Mercurius\Messenger\IncomingCallback;
use Mercurius\Messenger\OutgoingMessage;
use OpenCCK\App\Agent\CallbackRouter;
use OpenCCK\App\Service\OwnerDirectory;
use OpenCCK\App\Service\OwnersChannel;
use OpenCCK\Domain\Entity\MemberStatus;
use OpenCCK\Domain\Entity\Role;
use OpenCCK\Test\Support\FakeChannel;
use OpenCCK\Test\Support\InMemoryMemberRepository;
use OpenCCK\Test\Support\InMemoryOwnerCardRepository;

use function Mercurius\I18n\t;

/**
 * A card or a notice for the owner goes to every owner; once one of them decided, every copy shows who and what.
 *
 * spec: brief/participants.md#свои-роли — cards and notices go to every owner, any of them presses
 */
final class OwnersChannelTest extends AsyncTestCase {
    private InMemoryMemberRepository $members;
    private FakeChannel $inner;
    private OwnersChannel $channel;

    protected function setUp(): void {
        parent::setUp();
        $this->members = new InMemoryMemberRepository();
        $this->members->ensureOwner('telegram', '100', 'Анна');
        $this->inner = new FakeChannel();
        $this->channel = new OwnersChannel($this->inner, new OwnerDirectory($this->members, 'telegram', '100'), new InMemoryOwnerCardRepository());
    }

    private function secondOwner(): void {
        $next = $this->members->createVisitor('telegram', '200', 'Борис');
        $this->members->decideVisitor($next->id, Role::user(), MemberStatus::Active);
        $this->members->addOwner($next->id);
    }

    /** @return list<array{string, string}> sends: chat, text */
    private function sends(): array {
        return array_values(array_map(static fn(array $e): array => [$e[1], $e[2]], array_filter($this->inner->log, static fn(array $e): bool => $e[0] === 'send')));
    }

    public function testSendGoesToEveryOwner(): void {
        $this->secondOwner();

        $sent = $this->channel->send(new OutgoingMessage(OwnersChannel::OWNERS, 'Разрешить?', buttons: [[new Button('Да', 'ap:1:a')]]));

        self::assertTrue($sent->success);
        self::assertSame([['100', 'Разрешить?'], ['200', 'Разрешить?']], $this->sends());
        self::assertNotNull($sent->messageId);
        self::assertCount(2, $this->inner->buttons, 'both copies carry the buttons');
    }

    public function testEditOfOneCopyEditsAll(): void {
        $this->secondOwner();
        $card = $this->channel->send(new OutgoingMessage(OwnersChannel::OWNERS, 'Разрешить?'));
        $copies = array_values(array_map(static fn(array $e): string => $e[0], array_filter($this->inner->log, static fn(array $e): bool => $e[0] === 'send')));
        self::assertCount(2, $copies);

        $this->channel->pressedBy('Борис', fn() => $this->channel->edit('200', '101', 'Разрешено'));

        $edits = array_values(array_filter($this->inner->log, static fn(array $e): bool => $e[0] === 'edit'));
        self::assertSame(['100', '101'], array_map(static fn(array $e): string => $e[1], $edits), 'every copy');
        foreach ($edits as $edit) {
            self::assertSame('Разрешено' . "\n\n" . sprintf(t('owners.decided_by'), 'Борис'), $edit[2]);
        }
        self::assertNotNull($card->messageId);
        $this->channel->edit(OwnersChannel::OWNERS, $card->messageId, 'Истекло');
        self::assertCount(4, array_filter($this->inner->log, static fn(array $e): bool => $e[0] === 'edit'), 'the card is edited by its own id too');
    }

    public function testOwnerWithoutChatIsSkipped(): void {
        $next = $this->members->createAgent('peer', Role::user());
        self::assertFalse($this->members->addOwner($next->id), 'an agent is never an owner');

        $sent = $this->channel->send(new OutgoingMessage(OwnersChannel::OWNERS, 'Уведомление'));

        self::assertTrue($sent->success);
        self::assertSame([['100', 'Уведомление']], $this->sends());
    }

    public function testNoReachableOwnerFails(): void {
        $channel = new OwnersChannel($this->inner, new OwnerDirectory(new InMemoryMemberRepository(), 'telegram', ''), new InMemoryOwnerCardRepository());

        self::assertFalse($channel->send(new OutgoingMessage(OwnersChannel::OWNERS, 'Уведомление'))->success);
        self::assertSame([], $this->sends());
    }

    public function testOtherChatsPassThrough(): void {
        $this->channel->send(new OutgoingMessage('555', 'Привет'));
        $this->channel->edit('555', '100', 'Пока');

        self::assertSame([['send', '555', 'Привет'], ['edit', '100', 'Пока']], $this->inner->log);
    }

    public function testAPressThroughTheRouterNamesTheOwner(): void {
        $this->secondOwner();
        $this->channel->send(new OutgoingMessage(OwnersChannel::OWNERS, 'Разрешить?'));
        $channel = $this->channel;
        $handler = new class($channel) implements CallbackHandler {
            public function __construct(private readonly OwnersChannel $channel) {}

            public function handleCallback(IncomingCallback $callback): void {
                $this->channel->edit($callback->chatId, $callback->messageId ?? '', 'Разрешено');
            }
        };
        $router = new CallbackRouter($this->channel, new OwnerDirectory($this->members, 'telegram', '100'), ['ap' => $handler]);

        $router->handleCallback(new IncomingCallback('telegram', 'cb', '200', '200', 'Борис', '101', 'ap:1:a', 'u'));

        $edits = array_values(array_filter($this->inner->log, static fn(array $e): bool => $e[0] === 'edit'));
        self::assertCount(2, $edits);
        self::assertStringEndsWith(sprintf(t('owners.decided_by'), 'Борис'), $edits[0][2]);
    }

}
