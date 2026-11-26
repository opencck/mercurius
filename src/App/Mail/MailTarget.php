<?php

declare(strict_types=1);

namespace OpenCCK\App\Mail;

/**
 * A mailbox as an entry of our mail MCP names it: the connection (context and name), the host and login, the sender.
 * The password is not here: it goes to the process.
 *
 * spec: brief/skills-mcp.md#ящики-участников-и-проектов
 */
final class MailTarget {
    public function __construct(
        /** context|name, as the connection is known */
        public readonly string $key,
        public readonly string $context,
        public readonly string $name,
        public readonly string $host,
        public readonly string $user,
        public readonly string $from,
    ) {}
}
