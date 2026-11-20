<?php

declare(strict_types=1);

namespace OpenCCK\Test\Unit\App\Agent;

use Amp\PHPUnit\AsyncTestCase;
use Mercurius\Messenger\IncomingCallback;
use OpenCCK\App\Agent\HostKeyCards;
use OpenCCK\Test\Support\FakeChannel;
use OpenCCK\Test\Support\InMemoryServerRepository;

/**
 * The owner trusts the host key they saw on the card, and no other: a key that changed while the card waited is not
 * pinned by a press on the old card.
 *
 * spec: brief/decisions.md#решения-по-доработкам-2026-11-18
 */
final class HostKeyCardsTest extends AsyncTestCase {
    public function testAPressPinsOnlyTheKeyTheCardShowed(): void {
        $servers = new InMemoryServerRepository();
        $channel = new FakeChannel();
        $cards = new HostKeyCards($servers, $channel, '42');
        $server = $servers->add('web', 'web.example', 22, 'deploy', null, 'default');
        $servers->setPendingHostKey($server->id, 'SHA256:first');
        $cards->ask($server, 'SHA256:first', false);
        $buttons = end($channel->buttons);
        self::assertNotFalse($buttons);
        $trust = $buttons[0][0]->data;
        self::assertMatchesRegularExpression('~^hk:\d+:a:[0-9a-f]{12}$~', $trust, 'the button names the key it trusts');

        // the server answers with another key before the owner presses
        $servers->decideHostKey($server->id, false);
        $servers->setPendingHostKey($server->id, 'SHA256:second');
        $cards->handleCallback(new IncomingCallback('telegram', 'c1', '42', '42', null, '1', $trust, 'u1'));
        $after = $servers->find($server->id);
        self::assertNotNull($after);
        self::assertNull($after->hostKey, 'the press on the old card pins nothing');
        self::assertSame('SHA256:second', $after->pendingHostKey, 'the new key still waits for its own card');

        $cards->ask($after, 'SHA256:second', false);
        $buttons = end($channel->buttons);
        self::assertNotFalse($buttons);
        $cards->handleCallback(new IncomingCallback('telegram', 'c2', '42', '42', null, '2', $buttons[0][0]->data, 'u2'));
        $pinned = $servers->find($server->id);
        self::assertNotNull($pinned);
        self::assertSame('SHA256:second', $pinned->hostKey);
    }
}
