<?php

declare(strict_types=1);

namespace OpenCCK\Domain\Entity;

/**
 * spec: BRIEF.md#участники-люди-и-агенты — a new visitor waits for the owner's decision
 */
enum PrincipalStatus: string {
    case Active = 'active';
    case Pending = 'pending';
    case Rejected = 'rejected';
}
