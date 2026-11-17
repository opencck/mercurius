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
use OpenCCK\Test\Support\RecordingLogger;

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

    public function testCommentaryStaysAsItsOwnMessageAndTheRunGoesOnBelow(): void {
        $channel = new FakeChannel();
        $observer = $this->observer($channel);
        $observer->start();

        $observer->textDelta(self::finishedRun(), 'Вы отвечаете на #288.');
        $observer->commentary(self::finishedRun(), 'Вы отвечаете на #288.');
        $observer->toolStarted(self::finishedRun(), 'spending', '{}');
        $observer->commentary(self::finishedRun(), '  ');
        $observer->commentary(self::finishedRun(), '[SILENT]');
        $observer->finished(self::finishedRun(), 'Токены: 9012.', StopReason::Answered);

        self::assertSame([['send', '42', ChannelRunObserver::PLACEHOLDER], ['send', '42', ChannelRunObserver::PLACEHOLDER]], array_values(array_filter($channel->log, static fn(array $e): bool => $e[0] === 'send')));
        $edits = array_values(array_filter($channel->log, static fn(array $e): bool => $e[0] === 'edit'));
        self::assertContains(['edit', '100', 'Вы отвечаете на #288.'], $edits, 'the first message keeps the commentary');
        self::assertSame(['edit', '101', 'Токены: 9012.'], end($edits), 'the answer replaces the new status message below');
        self::assertNotContains(['edit', '100', 'Токены: 9012.'], $edits);
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

    public function testLostAnswerIsLogged(): void {
        $channel = new FakeChannel(sendFails: true);
        $logger = new RecordingLogger();
        $observer = new ChannelRunObserver($channel, '42', null, 0.0, fn(): float => $this->now, logger: $logger);
        $observer->start();

        $observer->finished(self::finishedRun(), 'Итог', StopReason::Answered);

        self::assertContains('Run answer was not delivered', array_column($logger->records, 'message'));
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

    public function testEveryDeliveredMessageRemembersItsRun(): void {
        $channel = new FakeChannel(maxLength: 20);
        $messages = new \OpenCCK\Test\Support\InMemoryRunMessageRepository();
        $observer = new ChannelRunObserver($channel, '42', null, 0.0, fn(): float => $this->now, messages: $messages);
        $observer->start();

        $observer->commentary(self::finishedRun(), 'Сначала проверю.');
        $observer->finished(self::finishedRun(), str_repeat('итог ', 6), StopReason::Answered);

        self::assertSame(['telegram:42:100' => 1, 'telegram:42:101' => 1, 'telegram:42:102' => 1], $messages->runs, 'the commentary, the answer and its continuation');
    }

    public function testAnswerOfALongRunComesAsANewMessage(): void {
        $channel = new FakeChannel();
        $observer = $this->observer($channel);
        $observer->start();
        // Telegram does not notify on edits: the owner of a long run must hear that it ended.
        $this->now += 120.0;

        $observer->finished(self::finishedRun(), 'Итог', StopReason::Answered);

        self::assertContains('Итог', $channel->texts('send'));
        self::assertSame([\Mercurius\I18n\t(ChannelRunObserver::ANSWER_BELOW)], $channel->texts('edit'));
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
