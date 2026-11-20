<?php

declare(strict_types=1);

namespace OpenCCK\Infrastructure\Module;

use DateTimeImmutable;
use OpenCCK\Domain\Repository\TraceRepository;
use OpenCCK\Infrastructure\API\AppModuleInterface;
use Psr\Log\LoggerInterface;
use Revolt\EventLoop;
use Throwable;

/**
 * Deletes full prompts and answers older than TRACE_RETENTION_DAYS: at start and then once a day. Steps and spend
 * stay; only the bulky payloads go — the server's disk is nearly full.
 *
 * spec: brief/operations.md#шаги-и-хранение — retention of payloads
 */
final class TraceRetentionModule implements AppModuleInterface {
    private ?string $timer = null;

    /** @param positive-int $days */
    public function __construct(
        private readonly TraceRepository $traces,
        private readonly int $days,
        private readonly LoggerInterface $logger,
        private readonly float $interval = 86_400.0,
    ) {}

    public function start(): void {
        EventLoop::defer($this->purge(...));
        $this->timer = EventLoop::repeat($this->interval, $this->purge(...));
    }

    public function stop(): void {
        if ($this->timer !== null) {
            EventLoop::cancel($this->timer);
            $this->timer = null;
        }
    }

    private function purge(): void {
        try {
            $count = $this->traces->purgePayloads(new DateTimeImmutable(sprintf('-%d days', $this->days)));
            if ($count > 0) {
                $this->logger->info('Old trace payloads purged', ['count' => $count, 'days' => $this->days]);
            }
        } catch (Throwable $e) {
            $this->logger->warning('Could not purge trace payloads', ['error' => $e::class]);
        }
    }
}
