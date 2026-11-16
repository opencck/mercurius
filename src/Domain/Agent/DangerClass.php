<?php

declare(strict_types=1);

namespace OpenCCK\Domain\Agent;

/**
 * spec: BRIEF.md#инструменты — danger class in the registry entry, not regexes over text
 */
enum DangerClass: string {
    case Read = 'read';
    case Write = 'write';
    case Irreversible = 'irreversible';
}
