<?php

declare(strict_types=1);

namespace OpenCCK\Test\Unit\App\Agent;

use Amp\PHPUnit\AsyncTestCase;
use Mercurius\Messenger\SendErrorKind;
use Mercurius\Messenger\SendResult;
use OpenCCK\App\Agent\ChannelRunObserver;
use OpenCCK\Domain\Agent\Run;
use OpenCCK\Domain\Agent\RunLimits;
use OpenCCK\Domain\Agent\RunStatus;
use OpenCCK\Domain\Agent\StopReason;
use OpenCCK\Test\Support\FakeChannel;

/**
 * The final answer reaches the chat whatever happens to the streamed preview.
 */
final class ChannelRunObserverTest extends AsyncTestCase {
    private float $now = 1000.0;

    private function observer(FakeChannel $channel): ChannelRunObserver {
        return new ChannelRunObserver($channel, '42', null, 0.0, fn(): float => $this->now);
    }

    private static function finishedRun(): Run {
        return new Run(1, 1, 1, null, 'm', RunStatus::Done, StopReason::Answered, new RunLimits(30, 1000, 900, 120));
    }

    public function testFinalEditWaitsOutAShortFlood(): void {
        $channel = new FakeChannel();
        $channel->editResults = [SendResult::failed(SendErrorKind::RateLimited, 'flood', 0)];
        $observer = $this->observer($channel);
        $observer->start();

        $observer->finished(self::finishedRun(), 'Итог', StopReason::Answered);

        self::assertSame(['Итог'], $channel->texts('edit'));
    }

    public function testFailedFinalEditBecomesANewMessage(): void {
        $channel = new FakeChannel();
        $channel->editResults = [SendResult::failed(SendErrorKind::Transient, 'down'), SendResult::failed(SendErrorKind::Transient, 'down')];
        $observer = $this->observer($channel);
        $observer->start();

        $observer->finished(self::finishedRun(), 'Итог', StopReason::Answered);

        self::assertContains('Итог', $channel->texts('send'), 'the answer is not lost');
    }

    public function testLongFloodIsSatOutInTheBackground(): void {
        $channel = new FakeChannel();
        // The flood is longer than the observer waits in line: the answer goes once it is over.
        $channel->editResults = [SendResult::failed(SendErrorKind::RateLimited, 'flood', 1)];
        $observer = new ChannelRunObserver($channel, '42', null, 0.0, fn(): float => $this->now, maxFloodWait: 0.5);
        $observer->start();

        $observer->finished(self::finishedRun(), 'Итог', StopReason::Answered);
        self::assertNotContains('Итог', $channel->texts('edit'), 'not yet');
        \Amp\delay(2.2);

        self::assertContains('Итог', $channel->texts('edit'));
    }

    public function testAnswerOfALongRunComesAsANewMessage(): void {
        $channel = new FakeChannel();
        $observer = $this->observer($channel);
        $observer->start();
        // Telegram does not notify on edits: the owner of a long run must hear that it ended.
        $this->now += 120.0;

        $observer->finished(self::finishedRun(), 'Итог', StopReason::Answered);

        self::assertContains('Итог', $channel->texts('send'));
        self::assertSame([ChannelRunObserver::ANSWER_BELOW], $channel->texts('edit'));
    }

    public function testQuickAnswerReplacesThePreview(): void {
        $channel = new FakeChannel();
        $observer = $this->observer($channel);
        $observer->start();
        $this->now += 5.0;

        $observer->finished(self::finishedRun(), 'Итог', StopReason::Answered);

        self::assertSame(['Итог'], $channel->texts('edit'));
        self::assertNotContains('Итог', $channel->texts('send'));
    }
}
