<?php

declare(strict_types=1);

namespace OpenCCK\Domain\Agent;

enum ApprovalStatus: string {
    case Pending = 'pending';
    case Approved = 'approved';
    case Denied = 'denied';
    case Expired = 'expired';
}
