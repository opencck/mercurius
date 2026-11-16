<?php

declare(strict_types=1);

namespace OpenCCK\Domain\Agent;

enum ToolCallStatus: string {
    case Pending = 'pending';
    case Running = 'running';
    case Done = 'done';
    case Failed = 'failed';
    case AwaitingApproval = 'awaiting_approval';
    case Denied = 'denied';
    case Interrupted = 'interrupted';
}
