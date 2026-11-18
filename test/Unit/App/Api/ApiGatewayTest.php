<?php

declare(strict_types=1);

namespace OpenCCK\Test\Unit\App\Api;

use Amp\PHPUnit\AsyncTestCase;
use Mercurius\Messenger\IncomingCallback;
use Mercurius\Messenger\IncomingMessage;
use Mercurius\Messenger\OutgoingMessage;
use Mercurius\Messenger\Websocket\WebsocketChannel;
use OpenCCK\App\Api\ApiGateway;
use OpenCCK\App\Api\ApiKeys;
use OpenCCK\App\Service\ApiKeyCommands;
use OpenCCK\Domain\Entity\Principal;
use OpenCCK\Domain\Entity\PrincipalStatus;
use OpenCCK\Domain\Entity\Role;
use OpenCCK\Test\Support\InMemoryApiKeyRepository;
use OpenCCK\Test\Support\InMemoryPrincipalRepository;
use OpenCCK\Test\Support\RecordingConnection;

/**
 * The second channel: a participant connects to the WebSocket API with a key the owner issued and talks to the agent
 * in a chat of their own, with the rights they have in Telegram.
 *
 * spec: BRIEF.md#второй-канал-websocket-api
 */
final class ApiGatewayTest extends AsyncTestCase {
    private InMemoryPrincipalRepository $principals;
    private ApiKeys $keys;
    private Principal $owner;
    /** @var list<IncomingMessage> */
    private array $messages = [];
    /** @var list<IncomingCallback> */
    private array $presses = [];

    protected function setUp(): void {
        parent::setUp();
        $this->principals = new InMemoryPrincipalRepository();
        $this->owner = $this->principals->ensureOwner('telegram', '42', 'Owner');
        $this->keys = new ApiKeys(new InMemoryApiKeyRepository(), $this->principals);
    }

    private function gateway(WebsocketChannel $channel): ApiGateway {
        return new ApiGateway($channel, $this->keys, fn(IncomingMessage $m) => $this->messages[] = $m, fn(IncomingCallback $c) => $this->presses[] = $c);
    }

    public function testAKeyIsShownOnceAndOnlyItsHashIsKept(): void {
        $key = $this->keys->issue($this->owner, 'laptop');

        self::assertMatchesRegularExpression('~^mk_[0-9a-f]{48}$~', $key);
        self::assertSame($this->owner->id, $this->keys->authenticate('Bearer ' . $key)?->id);
        self::assertNull($this->keys->authenticate('Bearer mk_' . str_repeat('0', 48)));
        self::assertNull($this->keys->authenticate($key), 'the scheme is required');
        self::assertStringNotContainsString($key, $this->keys->text());
        self::assertStringContainsString('laptop', $this->keys->text());
    }

    public function testARevokedKeyAndARejectedParticipantDoNotConnect(): void {
        $user = $this->principals->createVisitor('telegram', '77', 'Вася');
        $this->principals->decideVisitor($user->id, Role::User, PrincipalStatus::Active);
        $key = $this->keys->issue($user, '');
        self::assertNotNull($this->keys->authenticate('Bearer ' . $key));

        $this->principals->setRole($user->id, Role::User, PrincipalStatus::Rejected);
        self::assertNull($this->keys->authenticate('Bearer ' . $key), 'a rejected participant');

        $this->principals->setRole($user->id, Role::User, PrincipalStatus::Active);
        self::assertTrue($this->keys->revoke(1));
        self::assertNull($this->keys->authenticate('Bearer ' . $key), 'a revoked key');
    }

    public function testFramesOfAClientReachTheAgentAsItsParticipant(): void {
        $channel = new WebsocketChannel();
        $gateway = $this->gateway($channel);
        $client = new RecordingConnection();

        $session = $gateway->open($this->owner, $client);
        self::assertSame('ws:p' . $this->owner->id, $session->chatId);
        self::assertSame($this->owner->id, $this->principals->findByIdentity('ws', 'p' . $this->owner->id)?->id, 'the participant has an identity in the channel');

        $gateway->frame($session, '{"type":"message","text":"привет"}');
        self::assertCount(1, $this->messages);
        self::assertSame('ws', $this->messages[0]->channel);
        self::assertSame('p' . $this->owner->id, $this->messages[0]->userId);

        $gateway->frame($session, '{"type":"callback","data":"ap:1:y"}');
        self::assertCount(1, $this->presses);

        $gateway->frame($session, 'garbage');
        self::assertContains('error', array_column($client->frames, 'type'), 'a bad frame is answered, not dropped silently');

        $channel->send(new OutgoingMessage($session->chatId, 'ответ'));
        self::assertContains('ответ', array_column($client->frames, 'text'));

        $gateway->close($session);
        $channel->send(new OutgoingMessage($session->chatId, 'потом'));
        self::assertNotContains('потом', array_column($client->frames, 'text'));
    }

    public function testTheOwnerIssuesListsAndRevokesKeysByCommand(): void {
        $commands = new ApiKeyCommands($this->keys, $this->principals);

        $answer = $commands->handle('new 1 телефон');
        self::assertMatchesRegularExpression('~mk_[0-9a-f]{48}~', $answer);
        self::assertStringContainsString('телефон', $commands->handle('list'));
        self::assertStringContainsString('#1', $commands->handle('list'));
        $commands->handle('revoke 1');
        self::assertStringNotContainsString('телефон', $commands->handle('list'));
        self::assertStringContainsString('/apikey', $commands->handle('new 999'), 'an unknown participant: the usage');
    }
}
