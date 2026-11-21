<?php

declare(strict_types=1);

namespace OpenCCK\App\Agent\Tool;

/**
 * A tool whose action has a wider grant than this exact call: every changing command on this server, any model of
 * this role. The card offers it next to the exact one, named in words, so the owner chooses the width.
 *
 * spec: brief/agent-loop.md#разрешения-навсегда
 */
interface WideGrant {
    /**
     * @param array<string, mixed> $args
     * @return array{string, string}|null the key and what it allows, in words; null — this call has no wider grant
     */
    public function wideGrant(array $args): ?array;
}
