<?php

declare(strict_types=1);

namespace OpenCCK\Domain\Agent;

/**
 * spec: brief/agent-loop.md#запуск-и-цикл — run statuses
 */
enum RunStatus: string {
    case Running = 'running';
    case WaitingApproval = 'waiting_approval';
    case Done = 'done';
    case Failed = 'failed';
    case Stopped = 'stopped';

    public function isActive(): bool {
        return $this === self::Running || $this === self::WaitingApproval;
    }
}
