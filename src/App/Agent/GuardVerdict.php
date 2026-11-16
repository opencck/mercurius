<?php

declare(strict_types=1);

namespace OpenCCK\App\Agent;

enum GuardVerdict {
    case Ok;
    case Warn;
    case Halt;
}
