<?php

declare(strict_types=1);

namespace OpenCCK\Domain\Entity;

/**
 * Starting roles; the same roles apply to people and to other agents. Only a person can be the owner.
 *
 * spec: BRIEF.md#участники-люди-и-агенты
 */
enum Role: string {
    case Owner = 'owner';
    case Admin = 'admin';
    case User = 'user';
    /** Talks with the agent but uses no tools; a stranger the owner let in. */
    case Visitor = 'visitor';
}
