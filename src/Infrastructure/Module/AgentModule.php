<?php

declare(strict_types=1);

namespace OpenCCK\Infrastructure\Module;

use OpenCCK\App\Agent\ApprovalService;
use OpenCCK\App\Agent\Delegation\BackgroundDelegations;
use OpenCCK\App\Agent\RunScheduler;
use OpenCCK\Infrastructure\API\AppModuleInterface;
use Psr\Log\LoggerInterface;
use Revolt\EventLoop;
use Throwable;

/**
 * Agent runs across restarts: on start continues runs a restart interrupted, on stop pauses running ones
 * so they resume next time. Runs waiting for the owner stay waiting; approvals that ran out while the server was down
 * are closed at start, the rest by a timer.
 *
 * spec: brief/agent-loop.md#устойчивость
 * spec: brief/orchestration.md#часть-3--фоновое-делегирование — background workers go on after a restart
 * spec: brief/agent-loop.md#подтверждения-и-запреты — silence past the timeout is a denial
 */
final class AgentModule implements AppModuleInterface {
    private ?string $timer = null;

    public function __construct(
        private readonly RunScheduler $scheduler,
        private readonly LoggerInterface $logger,
        private readonly ?ApprovalService $approvals = null,
        private readonly float $expiryInterval = 30.0,
        private readonly ?BackgroundDelegations $background = null,
        /** sends again the final answers a failure or a restart kept from their chats */
        private readonly ?\OpenCCK\App\Agent\DeliveryRecovery $deliveries = null,
    ) {}

    public function start(): void {
        // Runs first, so a delivered report joins a resumed run instead of racing it; background workers are left
        // to their delegations.
        // spec: brief/agent-loop.md#доставка-итогового-ответа — answers that did not reach their chat go out before new ones
        $this->deliveries?->recover();
        $this->scheduler->resumeAll();
        $this->background?->resumeAll();
        $this->logger->info('Agent runs resumed');
        if ($this->approvals !== null) {
            $this->expire();
            $this->timer = EventLoop::repeat($this->expiryInterval, $this->expire(...));
        }
    }

    public function stop(): void {
        if ($this->timer !== null) {
            EventLoop::cancel($this->timer);
            $this->timer = null;
        }
        $this->background?->shutdown();
        $this->scheduler->shutdown();
        $this->logger->info('Agent runs paused');
    }

    private function expire(): void {
        try {
            $this->approvals?->expireDue();
        } catch (Throwable $e) {
            $this->logger->error('Could not expire approvals', ['error' => $e::class]);
        }
    }
}
