<?php

declare(strict_types=1);

namespace OpenCCK\Test\Unit\App\Service;

use Amp\PHPUnit\AsyncTestCase;
use DateTimeImmutable;
use Mercurius\Messenger\IncomingCallback;
use Mercurius\Messenger\IncomingMessage;
use OpenCCK\App\Service\VisitorService;
use OpenCCK\Domain\Entity\PrincipalStatus;
use OpenCCK\Domain\Entity\Role;
use OpenCCK\Test\Support\FakeChannel;
use OpenCCK\Test\Support\InMemoryPrincipalRepository;

/**
 * In a big group the owner gets one card of strangers instead of a card per stranger: the first strangers of a group
 * get their own cards, the next ones are gathered on one card that is edited, with buttons for all of them at once.
 */
final class VisitorGroupSummaryTest extends AsyncTestCase {
    private InMemoryPrincipalRepository $principals;
    private FakeChannel $channel;
    private VisitorService $visitors;

    protected function setUp(): void {
        parent::setUp();
        $this->principals = new InMemoryPrincipalRepository();
        $this->principals->ensureOwner('telegram', '42', 'Owner');
        $this->channel = new FakeChannel(4096);
        $this->visitors = new VisitorService($this->principals, $this->channel, '42', retryDelays: []);
    }

    private function stranger(string $id, string $name, string $chat = '-100500'): void {
        $this->visitors->admit(new IncomingMessage('telegram', $chat, $id, null, $name, 'всем привет', '1' . $id, null, false, new DateTimeImmutable(), 'u' . $id), 'Клуб', $chat);
    }

    public function testStrangersAfterTheFirstOnesShareOneEditedCard(): void {
        foreach (['101' => 'Аня', '102' => 'Боря', '103' => 'Вера', '104' => 'Гоша', '105' => 'Дина'] as $id => $name) {
            $this->stranger((string) $id, $name);
        }

        $sent = $this->channel->texts('send');
        self::assertCount(VisitorService::GROUP_SINGLE_CARDS + 1, $sent, 'two own cards and one summary');
        $summary = (array_slice($this->channel->log, -1)[0] ?? ["", "", ""]);
        self::assertSame('edit', $summary[0], 'the summary is edited, not sent again');
        self::assertStringContainsString('Вера', $summary[2]);
        self::assertStringContainsString('Дина', $summary[2]);
        self::assertStringContainsString('3', $summary[2]);
        $buttons = $this->channel->buttons[$summary[1]];
        $data = array_map(static fn($b) => $b->data, array_merge(...$buttons));
        self::assertSame(['pv:g1:t', 'pv:g1:r'], $data);
    }

    public function testOneButtonDecidesEveryoneOnTheSummary(): void {
        foreach (['101', '102', '103', '104'] as $id) {
            $this->stranger($id, 'Гость ' . $id);
        }
        $summaryId = (array_slice($this->channel->log, -1)[0] ?? ["", "", ""])[1];

        $this->visitors->handleCallback(new IncomingCallback('telegram', 'cb', '42', '42', 'Owner', $summaryId, 'pv:g1:t', 'u1'));

        $third = $this->principals->findByIdentity('telegram', '103');
        $fourth = $this->principals->findByIdentity('telegram', '104');
        $first = $this->principals->findByIdentity('telegram', '101');
        self::assertNotNull($third);
        self::assertSame(PrincipalStatus::Active, $third->status);
        self::assertSame(Role::visitor(), $third->role);
        self::assertSame(PrincipalStatus::Active, $fourth?->status);
        self::assertSame(PrincipalStatus::Pending, $first?->status, 'a stranger with an own card waits for that card');
        self::assertSame([], $this->channel->buttons[$summaryId], 'the decided summary has no buttons left');
    }

    public function testAnotherGroupHasItsOwnCount(): void {
        $this->stranger('101', 'Аня');
        $this->stranger('102', 'Боря');
        $this->stranger('201', 'Женя', '-100600');

        self::assertCount(3, $this->channel->texts('send'), 'the first stranger of another group gets an own card');
        self::assertSame([], $this->channel->texts('edit'));
    }

    public function testAnOldSummaryButtonAnswersThatItIsGone(): void {
        $this->visitors->handleCallback(new IncomingCallback('telegram', 'cb', '42', '42', 'Owner', '9', 'pv:g7:r', 'u1'));

        self::assertNotSame([], $this->channel->answers);
    }
}
