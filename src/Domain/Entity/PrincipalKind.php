<?php

declare(strict_types=1);

namespace OpenCCK\Domain\Entity;

enum PrincipalKind: string {
    case Human = 'human';
    case Agent = 'agent';
}
