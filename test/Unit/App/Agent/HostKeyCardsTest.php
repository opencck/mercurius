<?php

declare(strict_types=1);

namespace OpenCCK\Test\Unit\App\Agent;

use Amp\PHPUnit\AsyncTestCase;
use Mercurius\Messenger\IncomingCallback;
use OpenCCK\App\Agent\HostKeyCards;
use OpenCCK\App\Server\SshTarget;
use OpenCCK\Test\Support\FakeChannel;
use OpenCCK\Test\Support\InMemorySshHostKeyRepository;

/**
 * The owner trusts the host key they saw on the card, and no other: a key that changed while the card waited is not
 * pinned by a press on the old card. A changed key is a warning with a way to forget the trusted one.
 *
 * spec: brief/servers.md#серверы-и-сеть
 * spec: brief/servers.md#ssh-как-предустановленный-mcp — the host key of an entry, kept in the database by the entry
 */
final class HostKeyCardsTest extends AsyncTestCase {
    private InMemorySshHostKeyRepository $keys;
    private FakeChannel $channel;
    private HostKeyCards $cards;
    private SshTarget $web;

    protected function setUp(): void {
        parent::setUp();
        $this->keys = new InMemorySshHostKeyRepository();
        $this->channel = new FakeChannel();
        $this->cards = new HostKeyCards($this->keys, $this->channel, '42');
        $this->web = new SshTarget('member:1|web', 'member:1', 'web', 'web.example', 22, 'deploy');
    }

    /** @return list<string> */
    private function lastButtons(): array {
        $buttons = end($this->channel->buttons);
        self::assertNotFalse($buttons);
        return array_map(static fn(\Mercurius\Messenger\Button $b): string => $b->data, $buttons[0]);
    }

    private function lastText(): string {
        $sent = array_values(array_filter($this->channel->log, static fn(array $l): bool => $l[0] === 'send'));
        return $sent === [] ? '' : $sent[count($sent) - 1][2];
    }

    private function press(string $data): void {
        $this->cards->handleCallback(new IncomingCallback('telegram', 'c' . mt_rand(), '42', '42', null, '1', $data, 'u' . mt_rand()));
    }

    public function testAPressPinsOnlyTheKeyTheCardShowed(): void {
        $row = $this->keys->setPending('member:1|web', 'web.example:22', 'SHA256:first');
        $this->cards->ask($this->web, $row, 'SHA256:first', false, false);
        $trust = $this->lastButtons()[0];
        self::assertMatchesRegularExpression('~^hk:\d+:a:[0-9a-f]{12}$~', $trust, 'the button names the key it trusts');
        self::assertStringContainsString('deploy@web.example:22', $this->lastText());

        // the server answers with another key before the owner presses
        $row = $this->keys->setPending('member:1|web', 'web.example:22', 'SHA256:second');
        $this->press($trust);
        self::assertNull($this->keys->find($row->id)?->hostKey, 'the press on the old card pins nothing');
        self::assertSame('SHA256:second', $this->keys->find($row->id)?->pending, 'the new key still waits for its own card');

        $this->cards->ask($this->web, $row, 'SHA256:second', false, false);
        $this->press($this->lastButtons()[0]);
        self::assertSame('SHA256:second', $this->keys->find($row->id)?->hostKey);
    }

    public function testAChangedKeyMayBeForgottenSoTheNextConnectionAsksAgain(): void {
        $this->keys->pin('member:1|web', 'web.example:22', 'SHA256:old');
        $row = $this->keys->of('member:1|web', 'web.example:22') ?? self::fail();

        $this->cards->ask($this->web, $row, 'SHA256:new', true, false);
        $buttons = $this->lastButtons();
        self::assertCount(1, $buttons, 'a changed key is never trusted from the card');
        self::assertStringContainsString('SHA256:old', $this->lastText());

        $this->press($buttons[0]);

        self::assertNull($this->keys->find($row->id)?->hostKey);
    }

    public function testTheKeyOfAServerInBetweenNamesItsAddress(): void {
        $inner = new SshTarget('member:1|inner', 'member:1', 'inner', '10.0.0.5', 22, 'app', 'default', 'bastion', 22, 'ops');
        $row = $this->keys->setPending('member:1|inner', 'bastion:22', 'SHA256:j');

        $this->cards->ask($inner, $row, 'SHA256:j', false, true);

        self::assertStringContainsString('ops@bastion:22', $this->lastText());
    }
}
