<?php

declare(strict_types=1);

namespace OpenCCK\Infrastructure\Module;

use OpenCCK\App\Agent\RunScheduler;
use OpenCCK\Infrastructure\API\AppModuleInterface;
use Psr\Log\LoggerInterface;

/**
 * Agent runs across restarts: on start continues runs a restart interrupted, on stop pauses running ones
 * so they resume next time.
 *
 * spec: BRIEF.md#устойчивость
 */
final class AgentModule implements AppModuleInterface {
    public function __construct(
        private readonly RunScheduler $scheduler,
        private readonly LoggerInterface $logger,
    ) {}

    public function start(): void {
        $this->scheduler->resumeAll();
        $this->logger->info('Agent runs resumed');
    }

    public function stop(): void {
        $this->scheduler->shutdown();
        $this->logger->info('Agent runs paused');
    }
}
