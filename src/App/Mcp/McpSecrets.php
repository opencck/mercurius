<?php

declare(strict_types=1);

namespace OpenCCK\App\Mcp;

use Closure;
use OpenCCK\App\Vault\Vault;

/**
 * The secrets of an entry, from the layers of its context: in a project — the project, its owner, the agent; in a
 * personal context — the member, the agent; elsewhere — the agent. The approval of the entry is the consent to the
 * names it carries: its card says which layer each comes from.
 *
 * spec: brief/skills-mcp.md#подстановка-секретов
 */
final class McpSecrets {
    /** @param Closure(int): ?int $projectOwner the member whose secrets are the owner's layer of a project */
    public function __construct(
        private readonly Vault $vault,
        private readonly Closure $projectOwner,
    ) {}

    public function render(McpEntry $entry, string $context): McpRendered {
        [$project, $owner] = $this->layersOf($context);
        return $entry->render(fn(string $name): ?string => $this->vault->layered($name, $project, $owner)[0] ?? null);
    }

    /**
     * Each name of the entry and the layer it comes from, null — none has it. For the card; no value leaves.
     *
     * @return array<string, ?string>
     */
    public function layers(McpEntry $entry, string $context): array {
        [$project, $owner] = $this->layersOf($context);
        $layers = [];
        foreach ($entry->placeholders() as $name) {
            $layers[$name] = $this->vault->layered($name, $project, $owner)[1] ?? null;
        }
        return $layers;
    }

    /** @return array{?int, int} the project and the owner of the context; 0 — no owner layer */
    private function layersOf(string $context): array {
        if (preg_match('~^member:(\d+)$~', $context, $m) === 1) {
            return [null, (int) $m[1]];
        }
        if (preg_match('~^project:(\d+)$~', $context, $m) === 1) {
            return [(int) $m[1], ($this->projectOwner)((int) $m[1]) ?? 0];
        }
        return [null, 0];
    }
}
