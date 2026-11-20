<?php

declare(strict_types=1);

namespace OpenCCK\Infrastructure\Module;

use OpenCCK\App\Peer\A2aServer;
use OpenCCK\App\Peer\PeerNetwork;
use OpenCCK\Infrastructure\API\AppModuleInterface;
use Psr\Log\LoggerInterface;
use Revolt\EventLoop;
use Throwable;

/**
 * Every half a minute: the state of tasks given to neighbours, and stale tasks taken from them fail.
 *
 * spec: brief/network.md#сеть-агентов-1
 */
final class PeerModule implements AppModuleInterface {
    private ?string $timer = null;

    public function __construct(
        private readonly PeerNetwork $network,
        private readonly ?A2aServer $server,
        private readonly LoggerInterface $logger,
        private readonly float $interval = 30.0,
    ) {}

    public function start(): void {
        $this->timer = EventLoop::repeat($this->interval, function (): void {
            try {
                $this->network->poll();
                $failed = $this->server?->sweep() ?? 0;
                if ($failed > 0) {
                    $this->logger->warning('Stale A2A tasks failed', ['tasks' => $failed]);
                }
            } catch (Throwable $e) {
                $this->logger->warning('Peer tick failed', ['error' => $e::class]);
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
