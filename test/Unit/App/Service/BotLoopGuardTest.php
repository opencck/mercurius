<?php

declare(strict_types=1);

namespace OpenCCK\Test\Unit\App\Service;

use OpenCCK\App\Service\BotLoopGuard;
use PHPUnit\Framework\TestCase;

/**
 * spec: brief/channels.md#группа — two bots in one group stop answering each other
 */
final class BotLoopGuardTest extends TestCase {
    private float $now = 1000.0;

    private function guard(): BotLoopGuard {
        return new BotLoopGuard(fn(): float => $this->now);
    }

    public function testAnExchangeOfBotsEndsAfterFourHops(): void {
        $guard = $this->guard();
        $guard->human('g');
        $passed = [];
        for ($i = 1; $i <= 6; $i++) {
            $passed[] = $guard->admit('g', 'bot' . ($i % 2), 'ответ ' . $i);
        }
        self::assertSame([true, true, true, true, false, false], $passed);

        $guard->human('g');
        self::assertTrue($guard->admit('g', 'bot1', 'снова'), 'a person starts a new exchange');
    }

    public function testTheSameAskOfABotCountsOnce(): void {
        $guard = $this->guard();
        self::assertTrue($guard->admit('g', 'bot1', 'Привет,  бот'));
        self::assertFalse($guard->admit('g', 'bot1', 'Привет, бот'), 'the same words again');
        self::assertTrue($guard->admit('g', 'bot2', 'Привет, бот'), 'another bot');
    }

    public function testTooManyBotMessagesCoolTheChatDown(): void {
        $guard = $this->guard();
        $passed = 0;
        for ($i = 0; $i < 25; $i++) {
            $guard->human('g');
            $passed += $guard->admit('g', 'bot', 'n' . $i) ? 1 : 0;
            $this->now += 5.0;
        }
        self::assertSame(20, $passed, '20 in five minutes');
        $this->now += 601.0;
        $guard->human('g');
        self::assertTrue($guard->admit('g', 'bot', 'после паузы'));
        self::assertTrue($guard->admit('other', 'bot', 'n'), 'chats are apart');
    }
}
