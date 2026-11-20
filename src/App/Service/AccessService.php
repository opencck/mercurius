<?php

declare(strict_types=1);

namespace OpenCCK\App\Service;

use Mercurius\Messenger\IncomingMessage;
use OpenCCK\Domain\Entity\Principal;
use OpenCCK\Domain\Repository\PrincipalRepository;

/**
 * Who is writing — a person or another agent. The owner is recognised by the configured channel account
 * and created on first contact. A stranger writing in a private chat becomes a pending visitor shown to the owner;
 * without the visitor service, or in a group, a stranger is refused.
 *
 * spec: brief/decisions.md#участники-люди-и-агенты
 */
final class AccessService {
    public function __construct(
        private readonly PrincipalRepository $principals,
        private readonly string $ownerChannel,
        private readonly string $ownerExternalId,
        private readonly ?VisitorService $visitors = null,
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

    public function resolveMessage(IncomingMessage $message): ?Principal {
        $principal = $this->resolve($message->channel, $message->userId, $message->displayName ?? $message->userName);
        if ($principal !== null || $this->visitors === null || !$message->isPrivateChat) {
            return $principal;
        }
        return $this->visitors->admit($message);
    }
}
