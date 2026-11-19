<?php

declare(strict_types=1);

namespace OpenCCK\App\Service;

use Closure;

/**
 * Keeps bots of a group from talking to each other without end. A message of a person starts a new exchange; after it
 * each message of a bot is a hop, and past four hops bots are not answered until a person writes again. A bot saying
 * the same words again in one exchange is not a new ask. Over everything, a chat with 20 bot messages in five minutes
 * is not answered to bots for ten minutes.
 *
 * spec: BRIEF.md#группа — bots in groups and the guard against loops
 * ref: references/hermes-agent/gateway/bot_loop_guard.py@e96221fa:L28-L35 (BotLoopGuardSettings) — 20 in 300 s, a 600 s
 *      cooldown, 4 hops; L136-L187 (admit, chain_admit) — the window, the chain reset by a person, a repeated ask
 *      counts once. hermes also refuses a bot answering this bot's own reply (reply_to_reply); here the four hops end
 *      such a pair soon enough, and noting delivered replies to bots would need the run's delivery to report back
 */
final class BotLoopGuard {
    private const MAX_EVENTS = 20;
    private const WINDOW = 300.0;
    private const COOLDOWN = 600.0;
    private const MAX_HOPS = 4;

    /** @var array<string, list<float>> chat => times of admitted bot messages */
    private array $events = [];
    /** @var array<string, float> chat => until when bots are not answered */
    private array $cooldown = [];
    /** @var array<string, array{hops: int, asks: array<string, true>, updated: float}> chat => the exchange since a person */
    private array $chains = [];
    /** @var Closure(): float */
    private readonly Closure $clock;

    /** @param (Closure(): float)|null $clock seconds, for tests */
    public function __construct(?Closure $clock = null) {
        $this->clock = $clock ?? static fn(): float => microtime(true);
    }

    /** A person wrote in the chat: the exchange of bots starts anew. */
    public function human(string $chat): void {
        unset($this->chains[$chat]);
    }

    /** Whether the agent may take a message of bot $sender in $chat; counts it when it may. */
    public function admit(string $chat, string $sender, string $text): bool {
        $now = ($this->clock)();
        $this->sweep($now);
        if (($this->cooldown[$chat] ?? 0.0) > $now) {
            return false;
        }
        $chain = $this->chains[$chat] ?? null;
        if ($chain === null || $now - $chain['updated'] > self::COOLDOWN) {
            $chain = ['hops' => 0, 'asks' => [], 'updated' => $now];
        }
        $chain['updated'] = $now;
        $ask = $sender . "\0" . hash('sha256', implode(' ', preg_split('~\s+~u', trim($text)) ?: []));
        if (isset($chain['asks'][$ask]) || $chain['hops'] >= self::MAX_HOPS) {
            $this->chains[$chat] = $chain;
            return false;
        }
        $events = array_values(array_filter($this->events[$chat] ?? [], static fn(float $at): bool => $at > $now - self::WINDOW));
        if (count($events) >= self::MAX_EVENTS) {
            $this->cooldown[$chat] = $now + self::COOLDOWN;
            $this->events[$chat] = [];
            return false;
        }
        $events[] = $now;
        $this->events[$chat] = $events;
        $chain['hops']++;
        $chain['asks'][$ask] = true;
        $this->chains[$chat] = $chain;
        return true;
    }

    /** Idle chats are forgotten so memory stays small. */
    private function sweep(float $now): void {
        foreach ($this->events as $chat => $times) {
            if ($times === [] || end($times) <= $now - self::WINDOW) {
                unset($this->events[$chat]);
            }
        }
        $this->cooldown = array_filter($this->cooldown, static fn(float $until): bool => $until > $now);
        $this->chains = array_filter($this->chains, static fn(array $c): bool => $now - $c['updated'] <= self::COOLDOWN);
    }
}
