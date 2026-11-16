<?php

declare(strict_types=1);

namespace OpenCCK\App\Service;

use OpenCCK\Domain\Entity\Principal;
use OpenCCK\Domain\Repository\PrincipalRepository;

/**
 * Who is writing — a person or another agent. The owner is recognised by the configured channel account
 * and created on first contact; anyone without a bound identity is refused.
 *
 * spec: BRIEF.md#участники-люди-и-агенты
 */
final class AccessService {
    public function __construct(
        private readonly PrincipalRepository $principals,
        private readonly string $ownerChannel,
        private readonly string $ownerExternalId,
    ) {}

    public function resolve(string $channel, string $externalId, ?string $displayName): ?Principal {
        $principal = $this->principals->findByIdentity($channel, $externalId);
        if ($principal !== null) {
            return $principal;
        }
        if ($channel === $this->ownerChannel && $externalId === $this->ownerExternalId) {
            return $this->principals->ensureOwner($channel, $externalId, $displayName);
        }
        return null;
    }
}
