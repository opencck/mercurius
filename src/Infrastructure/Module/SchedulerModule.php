<?php

declare(strict_types=1);

namespace OpenCCK\Infrastructure\Module;

use OpenCCK\App\Job\DailyDigest;
use OpenCCK\App\Job\JobScheduler;
use OpenCCK\Domain\Repository\JobRepository;
use OpenCCK\Infrastructure\API\AppModuleInterface;
use Psr\Log\LoggerInterface;
use Revolt\EventLoop;
use Throwable;

/**
 * Wakes the scheduler every half a minute: with no due job it does nothing and spends no tokens. The daily summary is
 * checked on the same tick.
 *
 * spec: BRIEF.md#планировщик-и-проекты — a wake gate without tokens when there is no work
 */
final class SchedulerModule implements AppModuleInterface {
    private ?string $timer = null;

    public function __construct(
        private readonly JobScheduler $scheduler,
        private readonly JobRepository $jobs,
        private readonly LoggerInterface $logger,
        private readonly ?DailyDigest $digest = null,
        private readonly float $interval = 30.0,
    ) {}

    public function start(): void {
        $reset = $this->jobs->resetRunning();
        if ($reset > 0) {
            $this->logger->warning('Jobs interrupted by a restart', ['jobs' => $reset]);
        }
        $this->timer = EventLoop::repeat($this->interval, function (): void {
            try {
                $this->scheduler->tick();
                $this->digest?->tick();
            } catch (Throwable $e) {
                $this->logger->warning('Scheduler tick failed', ['error' => $e::class]);
            }
        });
    }

    public function stop(): void {
        if ($this->timer !== null) {
            EventLoop::cancel($this->timer);
            $this->timer = null;
        }
    }
}
