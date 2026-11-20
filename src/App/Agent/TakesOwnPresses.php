<?php

declare(strict_types=1);

namespace OpenCCK\App\Agent;

use Mercurius\Messenger\IncomingCallback;

/**
 * A handler of presses that knows the presses of a participant on their own cards: the router lets those through,
 * everything else under its prefix stays the owner's.
 *
 * spec: brief/skills-mcp.md#ящики-участников-и-проектов — the owner of a mailbox confirms what is sent from it
 */
interface TakesOwnPresses {
    public function ownPress(IncomingCallback $callback): bool;
}
