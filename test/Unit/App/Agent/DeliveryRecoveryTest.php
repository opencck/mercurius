<?php

declare(strict_types=1);

namespace OpenCCK\Test\Unit\App\Agent;

use Amp\PHPUnit\AsyncTestCase;
use Mercurius\Messenger\SendErrorKind;
use Mercurius\Messenger\SendResult;
use OpenCCK\App\Agent\ChannelRunObserver;
use OpenCCK\App\Agent\DeliveryRecovery;
use OpenCCK\Domain\Agent\Delivery;
use OpenCCK\Domain\Agent\Run;
use OpenCCK\Domain\Agent\RunLimits;
use OpenCCK\Domain\Agent\RunStatus;
use OpenCCK\Domain\Agent\StopReason;
use OpenCCK\Test\Support\FakeChannel;
use OpenCCK\Test\Support\InMemoryDeliveryRepository;
use Psr\Log\NullLogger;

/**
 * A final answer that did not reach the chat is sent again after a restart, from the part that was not delivered.
 */
final class DeliveryRecoveryTest extends AsyncTestCase {
    private float $now = 1000.0;

    private static function finishedRun(): Run {
        return new Run(7, 1, 1, null, 'm', RunStatus::Done, StopReason::Answered, new RunLimits(30, 1000, 900, 120));
    }

    private function observer(FakeChannel $channel, InMemoryDeliveryRepository $deliveries): ChannelRunObserver {
        return new ChannelRunObserver($channel, '42', null, 0.0, fn(): float => $this->now, maxFloodWait: 0.0, deliveries: $deliveries);
    }

    public function testADeliveredAnswerLeavesNothingToRecover(): void {
        $deliveries = new InMemoryDeliveryRepository();
        $channel = new FakeChannel();
        $observer = $this->observer($channel, $deliveries);
        $observer->start();
        $observer->finished(self::finishedRun(), 'Итог', StopReason::Answered);

        self::assertSame(Delivery::DELIVERED, $deliveries->all()[0]->status);
        $later = new FakeChannel();
        (new DeliveryRecovery($deliveries, $later, new NullLogger()))->recover();
        self::assertSame([], $later->texts('send'));
    }

    public function testAnAnswerCutShortGoesOnFromThePartThatWasLost(): void {
        $deliveries = new InMemoryDeliveryRepository();
        $channel = new FakeChannel(maxLength: 20);
        $channel->editResults = [SendResult::failed(SendErrorKind::Transient, 'down')];
        $channel->sendResults = [SendResult::ok('1'), SendResult::failed(SendErrorKind::Transient, 'down')];
        $observer = $this->observer($channel, $deliveries);
        $observer->start();
        $channel->sendResults = [SendResult::ok('1'), SendResult::failed(SendErrorKind::Transient, 'down'), SendResult::failed(SendErrorKind::Transient, 'down')];

        $observer->finished(self::finishedRun(), 'первая часть ответа второй кусок третий хвост', StopReason::Answered);

        $delivery = $deliveries->all()[0];
        self::assertSame([Delivery::FAILED, 1], [$delivery->status, $delivery->sent]);

        $later = new FakeChannel(maxLength: 20);
        (new DeliveryRecovery($deliveries, $later, new NullLogger()))->recover();

        $sent = $later->texts('send');
        self::assertStringStartsWith('♻', $sent[0], 'a resent answer may be a duplicate and says so');
        self::assertStringNotContainsString('первая', implode(' ', $sent), 'the part that arrived is not sent again');
        self::assertStringContainsString('хвост', implode(' ', $sent));
        self::assertSame(Delivery::DELIVERED, $deliveries->all()[0]->status);
    }

    public function testOldOrTooOftenTriedAnswersAreGivenUp(): void {
        $deliveries = new InMemoryDeliveryRepository();
        $deliveries->open(7, 'telegram', '42', null, null, 'старый ответ', 'markdown', time() - 2 * 86400);
        $tried = $deliveries->open(8, 'telegram', '42', null, null, 'много попыток', 'markdown');
        for ($i = 0; $i < 3; $i++) {
            $deliveries->failed($tried, 'down');
        }
        $channel = new FakeChannel();

        (new DeliveryRecovery($deliveries, $channel, new NullLogger()))->recover();

        self::assertSame([], $channel->texts('send'));
        self::assertSame([Delivery::ABANDONED, Delivery::ABANDONED], array_map(static fn(Delivery $d): string => $d->status, $deliveries->all()));
    }
}
