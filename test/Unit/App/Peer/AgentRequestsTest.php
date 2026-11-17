<?php

declare(strict_types=1);

namespace OpenCCK\Test\Unit\App\Peer;

use Amp\PHPUnit\AsyncTestCase;
use Mercurius\Messenger\IncomingCallback;
use OpenCCK\App\Peer\AgentIdentity;
use OpenCCK\App\Peer\AgentRequests;
use OpenCCK\Domain\Entity\PrincipalKind;
use OpenCCK\Domain\Entity\Role;
use OpenCCK\Test\Support\FakeChannel;
use OpenCCK\Test\Support\InMemoryPeerRepository;
use OpenCCK\Test\Support\InMemoryPrincipalRepository;

/**
 * An agent the owner has not let in knocks like a stranger writing to the bot: the owner gets one card and decides —
 * a user, an admin, or a refusal.
 */
final class AgentRequestsTest extends AsyncTestCase {
    private FakeChannel $channel;
    private InMemoryPeerRepository $peers;
    private InMemoryPrincipalRepository $principals;
    private AgentRequests $requests;
    private string $fingerprint;
    private \OpenCCK\Test\Support\InMemoryConversationRepository $conversations;
    private \OpenCCK\Test\Support\InMemoryTranscriptRepository $transcript;

    protected function setUp(): void {
        parent::setUp();
        $this->channel = new FakeChannel();
        $this->peers = new InMemoryPeerRepository();
        $this->principals = new InMemoryPrincipalRepository();
        $this->principals->ensureOwner('telegram', '42', 'Owner');
        $this->conversations = new \OpenCCK\Test\Support\InMemoryConversationRepository();
        $this->transcript = new \OpenCCK\Test\Support\InMemoryTranscriptRepository();
        $this->requests = new AgentRequests($this->peers, $this->principals, $this->channel, '42', maxPending: 2, retryDelays: [], conversations: $this->conversations, transcript: $this->transcript);
        $this->fingerprint = AgentIdentity::generate()->fingerprint();
    }

    private function press(string $data): void {
        $this->requests->handleCallback(new IncomingCallback('telegram', 'cb-' . $data, '42', '42', 'Owner', '100', $data, 'u-' . $data));
    }

    public function testAnUnknownAgentIsShownToTheOwnerOnce(): void {
        $why = $this->requests->knock($this->fingerprint, 'claude-tester', 'https://tester.example', 'ws');
        $this->requests->knock($this->fingerprint, 'claude-tester', 'https://tester.example', 'ws');

        self::assertStringContainsString($this->fingerprint, $why);
        self::assertStringContainsString('owner', $why);
        $cards = $this->channel->texts('send');
        self::assertCount(1, $cards, 'one card per key');
        self::assertStringContainsString('claude-tester', $cards[0]);
        self::assertStringContainsString('https://tester.example', $cards[0]);
        self::assertStringContainsString($this->fingerprint, $cards[0]);
        $buttons = array_map(static fn(\Mercurius\Messenger\Button $b): string => $b->data, $this->channel->buttons['100'][0] ?? []);
        self::assertSame(['pa:1:u', 'pa:1:a', 'pa:1:r'], $buttons);
        self::assertNull($this->peers->principalByFingerprint($this->fingerprint), 'not let in before the owner decides');
    }

    public function testTheOwnerLetsTheAgentInWithARole(): void {
        $this->requests->knock($this->fingerprint, 'claude-tester', '', 'http');

        $this->press('pa:1:a');
        $this->press('pa:1:u');

        $id = $this->peers->principalByFingerprint($this->fingerprint);
        self::assertNotNull($id);
        $agent = $this->principals->find($id);
        self::assertSame([PrincipalKind::Agent, Role::Admin, 'claude-tester'], [$agent?->kind, $agent?->role, $agent?->displayName]);
        self::assertSame('edit', $this->channel->log[1][0] ?? null, 'the card shows the decision');
        self::assertNotNull($this->channel->answers[1][1] ?? null, 'a second press is already decided');
        self::assertSame(Role::Admin, $this->principals->find($id)?->role, 'the second press changes nothing');
    }

    public function testATakenNameGetsATailOfTheKey(): void {
        $doorotar = $this->principals->createAgent('doorotar', Role::User);
        $this->peers->allowKey($doorotar->id, 'doorotar', 'SHA256:' . str_repeat('0', 64));

        $this->requests->knock($this->fingerprint, 'doorotar', '', 'ws');
        $this->press('pa:1:u');

        $id = $this->peers->principalByFingerprint($this->fingerprint);
        self::assertNotSame($doorotar->id, $id, 'a claimed name never takes over a known agent');
        self::assertSame('doorotar-' . substr($this->fingerprint, 7, 8), $this->principals->find((int) $id)?->displayName);
        self::assertSame($doorotar->id, $this->peers->principalByFingerprint('SHA256:' . str_repeat('0', 64)));
    }

    public function testARefusedAgentIsNotShownAgain(): void {
        $this->requests->knock($this->fingerprint, 'pest', '', 'ws');
        $this->press('pa:1:r');

        $why = $this->requests->knock($this->fingerprint, 'pest', '', 'ws');

        self::assertStringContainsString('refused', $why);
        self::assertCount(1, $this->channel->texts('send'));
        self::assertNull($this->peers->principalByFingerprint($this->fingerprint));
    }

    public function testTooManyWaitingAgentsAreNotShown(): void {
        $this->requests->knock(AgentIdentity::generate()->fingerprint(), 'a', '', 'ws');
        $this->requests->knock(AgentIdentity::generate()->fingerprint(), 'b', '', 'ws');

        $why = $this->requests->knock($this->fingerprint, 'c', '', 'ws');

        self::assertStringContainsString('later', $why);
        self::assertCount(2, $this->channel->texts('send'));
    }

    public function testACardThatDidNotReachTheOwnerIsSentOnTheNextKnock(): void {
        $this->channel->sendResults = [\Mercurius\Messenger\SendResult::failed(\Mercurius\Messenger\SendErrorKind::Transient, 'down')];

        $this->requests->knock($this->fingerprint, 'tester', '', 'ws');
        $this->requests->knock($this->fingerprint, 'tester', '', 'ws');

        self::assertCount(1, $this->channel->texts('send'));
    }

    public function testTheCardSaysWhoConnectsToWhomAndShowsNoPreviewOfTheClaimedAddress(): void {
        $this->requests->knock($this->fingerprint, 'mercurius-rekryt', 'https://mercurius-rekryt.example', 'ws');

        self::assertStringContainsString('Ко мне подключается агент', $this->channel->texts('send')[0] ?? '');
        self::assertTrue($this->channel->previewsOff[0] ?? false, 'the address is the agent\'s own words: Telegram must not open it');
    }

    public function testTheAgentKnowsAboutTheCardAndTheDecision(): void {
        $this->requests->knock($this->fingerprint, 'mercurius-rekryt', 'https://mercurius-rekryt.example', 'ws');
        $this->press('pa:1:u');

        $said = array_map(static fn(array $m): string => $m['message']->content, $this->transcript->messages);
        self::assertCount(2, $said, 'the card and the decision are in the owner\'s conversation');
        self::assertStringContainsString('mercurius-rekryt', $said[0]);
        self::assertStringContainsString('untrusted', $said[0], 'the name and the address are the agent\'s words');
        self::assertStringContainsString('user', $said[1]);
        $owner = $this->principals->findByIdentity('telegram', '42');
        self::assertSame($this->conversations->openConversation((int) $owner?->id, 'telegram', '42'), $this->transcript->messages[0]['conversation']);
    }

    public function testAClaimedNameAndAddressAreCleaned(): void {
        self::assertSame('evil-bot', AgentRequests::nameFrom('../Evil Bot!', $this->fingerprint));
        self::assertSame('agent-' . substr($this->fingerprint, 7, 8), AgentRequests::nameFrom('Агент', $this->fingerprint));
        self::assertSame('mercurius-doorotar', AgentRequests::nameFrom(AgentRequests::nameOfUrl('https://mercurius-doorotar.srv.rekryt.ru/'), $this->fingerprint));

        $this->requests->knock($this->fingerprint, 'x', "javascript:alert(1)\n", 'ws');
        self::assertStringNotContainsString('javascript', $this->channel->texts('send')[0] ?? '');
    }
}
