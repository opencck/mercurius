<?php

declare(strict_types=1);

namespace OpenCCK\App\Agent\Tool;

/**
 * A tool that takes a secret by name: when the caller has none of their own, the wider layers of the context — the
 * project owner's and the agent's, each only where the context inherits it.
 *
 * spec: brief/skills-mcp.md#подстановка-секретов
 */
trait WiderSecrets {
    /** @var (\Closure(string, ToolContext): ?string)|null */
    private ?\Closure $wider = null;

    /** @param \Closure(string, ToolContext): ?string $wider */
    public function useWiderSecrets(\Closure $wider): void {
        $this->wider = $wider;
    }

    private function widerSecret(string $name, ToolContext $context): ?string {
        $wider = $this->wider;
        $value = $wider === null ? null : $wider($name, $context);
        return $value === '' ? null : $value;
    }
}
