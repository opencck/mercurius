<?php

declare(strict_types=1);

namespace OpenCCK\Infrastructure\Module;

use OpenCCK\Infrastructure\API\AppModuleInterface;
use OpenCCK\Memory\App\Consolidation;
use Psr\Log\LoggerInterface;
use Revolt\EventLoop;
use Throwable;

/**
 * Runs the check of memory once a night, at the given hour of UTC.
 *
 * spec: BRIEF.md#консолидация-dreaming — once a night; nothing to do, nothing done
 */
final class MemoryConsolidationModule implements AppModuleInterface {
    private ?string $timer = null;

    public function __construct(
        private readonly Consolidation $consolidation,
        /** hour of the day, UTC */
        private readonly int $hour,
        private readonly LoggerInterface $logger,
    ) {}

    public function start(): void {
        $this->schedule();
    }

    public function stop(): void {
        if ($this->timer !== null) {
            EventLoop::cancel($this->timer);
            $this->timer = null;
        }
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
            $this->logger->info('Memory check', ['result' => mb_substr($this->consolidation->run(), 0, 200)]);
        } catch (Throwable $e) {
            $this->logger->warning('Memory check failed', ['error' => $e::class]);
        }
    }
}
