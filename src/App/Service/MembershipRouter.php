<?php

declare(strict_types=1);

namespace OpenCCK\App\Service;

use Mercurius\Messenger\IncomingMembership;
use Mercurius\Messenger\MembershipHandler;

/** Hands a membership change to every handler; each takes the chats it knows (groups, channels). */
final class MembershipRouter implements MembershipHandler {
    /** @param list<MembershipHandler> $handlers */
    public function __construct(private readonly array $handlers) {}

    public function handleMembership(IncomingMembership $change): void {
        foreach ($this->handlers as $handler) {
            $handler->handleMembership($change);
        }
    }
}
