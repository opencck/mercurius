<?php

declare(strict_types=1);

namespace OpenCCK\Infrastructure\Module;

use OpenCCK\Infrastructure\API\AppModuleInterface;
use Closure;
use Mercurius\Memory\App\Consolidation;
use Mercurius\Memory\App\LossGuard;
use Mercurius\Memory\Domain\ConsolidationJournal;
use Psr\Log\LoggerInterface;
use Revolt\EventLoop;
use Throwable;

/**
 * Once a night, at the given hour of UTC: the guard against a loss of memory always, the sleep (the check of memory)
 * while the owner lists a model for it; a night missed while the app was down is checked soon after the start.
 *
 * spec: brief/memory.md#консолидация-dreaming — once a night; nothing to do, nothing done
 * spec: brief/memory.md#страж-потерь — the guard every night, with or without sleep
 */
final class MemoryConsolidationModule implements AppModuleInterface {
    /** seconds after the start before a missed night is checked: the app settles first */
    public const CATCH_UP_DELAY = 120;
    private ?string $timer = null;
    private ?string $catchUp = null;

    public function __construct(
        private readonly Consolidation $consolidation,
        /** hour of the day, UTC */
        private readonly int $hour,
        private readonly LoggerInterface $logger,
        private readonly ?ConsolidationJournal $journal = null,
        private readonly ?LossGuard $guard = null,
        /** @var (Closure(): bool)|null whether sleep runs: a model is listed for it; null — always */
        private readonly ?Closure $sleeps = null,
    ) {}

    public function start(): void {
        $this->schedule();
        try {
            $last = $this->journal?->last();
        } catch (Throwable $e) {
            $this->logger->warning('Memory check journal was not read', ['error' => $e::class]);
            return;
        }
        if ($this->journal !== null && self::missed($last['at'] ?? null, $this->hour, time())) {
            $this->catchUp = EventLoop::unreference(EventLoop::delay(self::CATCH_UP_DELAY, function (): void {
                $this->catchUp = null;
                $this->run();
            }));
        }
    }

    public function stop(): void {
        foreach ([$this->timer, $this->catchUp] as $id) {
            if ($id !== null) {
                EventLoop::cancel($id);
            }
        }
        $this->timer = $this->catchUp = null;
    }

    /** Whether the last pass (unix seconds, null — none) is older than the latest $hour:00 UTC already passed. */
    public static function missed(?int $lastAt, int $hour, int $now): bool {
        $latest = $now + self::secondsUntil($hour, $now) - 86400;
        return $lastAt === null || $lastAt < $latest;
    }

    /** Seconds from $now until the next $hour:00 UTC. */
    public static function secondsUntil(int $hour, int $now): int {
        $today = intdiv($now, 86400) * 86400 + $hour * 3600;
        return ($today > $now ? $today : $today + 86400) - $now;
    }

    private function schedule(): void {
        $this->timer = EventLoop::unreference(EventLoop::delay((float) self::secondsUntil($this->hour, time()), function (): void {
            $this->run();
            $this->schedule();
        }));
    }

    private function run(): void {
        try {
            $alert = $this->guard?->run();
            if ($alert !== null) {
                $this->logger->warning('Memory loss', ['alert' => mb_substr($alert, 0, 200)]);
            }
        } catch (Throwable $e) {
            $this->logger->warning('Memory guard failed', ['error' => $e::class]);
        }
        try {
            if ($this->sleeps !== null && !($this->sleeps)()) {
                $this->logger->info('Memory sleep is off: no model listed');
                return;
            }
            $this->logger->info('Memory check', ['result' => mb_substr($this->consolidation->run(), 0, 200)]);
        } catch (Throwable $e) {
            $this->logger->warning('Memory check failed', ['error' => $e::class]);
        }
    }
}
