<?php

declare(strict_types=1);

namespace OpenCCK\Test\Unit\App\Mcp;

use Amp\PHPUnit\AsyncTestCase;
use Mercurius\Messenger\IncomingCallback;
use OpenCCK\App\Mcp\McpElicitations;
use OpenCCK\Test\Support\FakeChannel;

use function Amp\async;
use function Amp\delay;

/**
 * A server asks the owner of its entry: a card, a press, the answer of the protocol.
 *
 * spec: brief/skills-mcp.md#процессы-и-реестр-инструментов-по-контекстам — a request of the server for consent
 * ref: references/ssh-mcp/src/guard/elicitation.ts@e04ebf2:L43-L84 — the consent of ssh-mcp: confirm: boolean, action decides
 */
final class McpElicitationsTest extends AsyncTestCase {
    private FakeChannel $channel;

    protected function setUp(): void {
        parent::setUp();
        $this->channel = new FakeChannel();
    }

    /** member 7 answers in the private chat '700'; nobody in a project without a chat */
    private function elicitations(float $seconds = 5): McpElicitations {
        return new McpElicitations($this->channel, static fn(string $context): ?array => match ($context) {
            'member:7' => ['700', 7],
            'owners' => ['owners', null],
            default => null,
        }, $seconds);
    }

    /** @return array<string, mixed> */
    private static function consent(): array {
        return ['message' => "Confirm mutating command on \"prod\":\n\ntouch /tmp/x", 'requestedSchema' => ['type' => 'object', 'properties' => ['confirm' => ['type' => 'boolean', 'title' => 'Approve this command?']]]];
    }

    private function press(McpElicitations $e, string $action, string $user = '700'): void {
        $buttons = end($this->channel->buttons);
        self::assertNotFalse($buttons, 'a card was sent');
        $data = $buttons[0][$action === 'a' ? 0 : 1]->data;
        $e->handleCallback(new IncomingCallback('telegram', 'c' . mt_rand(), '700', $user, null, '1', $data, 'u' . mt_rand()));
    }

    public function testAConsentIsACardAndAPressIsTheAnswer(): void {
        $e = $this->elicitations();
        $answer = async(fn(): array => $e->ask('member:7', 'ssh', self::consent()));
        delay(0.01);

        self::assertStringContainsString('touch /tmp/x', implode("\n", $this->channel->texts('send')));
        $this->press($e, 'a');

        self::assertSame(['action' => 'accept', 'content' => ['confirm' => true]], $answer->await());
    }

    public function testARefusalIsDecline(): void {
        $e = $this->elicitations();
        $answer = async(fn(): array => $e->ask('member:7', 'ssh', self::consent()));
        delay(0.01);
        $this->press($e, 'd');

        self::assertSame(['action' => 'decline'], $answer->await());
    }

    public function testAnotherPersonsPressDecidesNothing(): void {
        $e = $this->elicitations(0.2);
        $answer = async(fn(): array => $e->ask('member:7', 'ssh', self::consent()));
        delay(0.01);
        $this->press($e, 'a', '800');

        self::assertSame(['action' => 'cancel'], $answer->await(), 'not answered: the time ran out');
    }

    public function testAFormBeyondAYesOrNoAndNobodyToAskAreDeclined(): void {
        $e = $this->elicitations();
        $form = ['message' => 'Your password', 'requestedSchema' => ['type' => 'object', 'properties' => ['password' => ['type' => 'string']]]];

        self::assertSame(['action' => 'decline'], $e->ask('member:7', 'ssh', $form), 'a secret is never typed into a card');
        self::assertSame(['action' => 'decline'], $e->ask('project:5', 'ssh', self::consent()), 'nobody to ask');
        self::assertSame([], $this->channel->texts('send'));
    }

    public function testAnUnansweredCardIsCancel(): void {
        self::assertSame(['action' => 'cancel'], $this->elicitations(0.05)->ask('member:7', 'ssh', self::consent()));
    }
}
