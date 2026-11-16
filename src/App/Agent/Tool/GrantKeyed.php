<?php

declare(strict_types=1);

namespace OpenCCK\App\Agent\Tool;

/**
 * A tool whose irreversible calls can be granted for good: the key names the action and its target
 * ("git-push:origin", "http:POST api.example.com"). Null — this call cannot be granted, ask every time.
 *
 * spec: BRIEF.md#подтверждения-и-запреты — saved grants keyed by action and target
 */
interface GrantKeyed {
    /** @param array<string, mixed> $args */
    public function grantKey(array $args): ?string;

    /** @param array<string, mixed> $args what the action is, in words, for the approval card */
    public function describe(array $args): string;
}
