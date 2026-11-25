<?php

declare(strict_types=1);

namespace OpenCCK\App\Mcp;

use Closure;
use OpenCCK\App\Vault\Vault;

/**
 * The secrets of an entry, from the layers of its context: in a project — the project, its owner, the agent; in a
 * personal context — the member, the agent. A wider layer counts only when the context inherits it, off by default:
 * the project owner shares their own with the project, an owner of the agent shares the agent's with a project or a
 * member. The approval of the entry is the consent to the names it carries: its card says which layer each comes from.
 *
 * spec: brief/skills-mcp.md#подстановка-секретов
 */
final class McpSecrets {
    /** @param Closure(int): ?int $projectOwner the member whose secrets are the owner's layer of a project */
    public function __construct(
        private readonly Vault $vault,
        private readonly Closure $projectOwner,
        private readonly \OpenCCK\Domain\Repository\SecretInheritanceRepository $inherit,
    ) {}

    public function render(McpEntry $entry, string $context): McpRendered {
        [$project, $owner, $withOwner, $withAgent] = $this->layersOf($context);
        return $entry->render(fn(string $name): ?string => $this->vault->layered($name, $project, $owner, $withOwner, $withAgent)[0] ?? null);
    }

    /**
     * Each name of the entry and the layer it comes from, null — none has it. For the card; no value leaves.
     *
     * @return array<string, ?string>
     */
    public function layers(McpEntry $entry, string $context): array {
        [$project, $owner, $withOwner, $withAgent] = $this->layersOf($context);
        $layers = [];
        foreach ($entry->placeholders() as $name) {
            $layers[$name] = $this->vault->layered($name, $project, $owner, $withOwner, $withAgent)[1] ?? null;
        }
        return $layers;
    }

    /**
     * A value from the layers wider than the project's own: the owner of the context and the agent, each only where
     * the context inherits it. For a request to a site, where a project's secret goes only to the site it is for.
     */
    public function outside(string $name, string $context): ?string {
        [, $owner, $withOwner, $withAgent] = $this->layersOf($context);
        return $this->vault->layered($name, null, $owner, $withOwner, $withAgent)[0] ?? null;
    }

    /** @return array{?int, int, bool, bool} the project, the owner of the context, and whether the owner's and the agent's layers count */
    private function layersOf(string $context): array {
        $inherits = $this->inherit->of($context);
        if (preg_match('~^member:(\d+)$~', $context, $m) === 1) {
            // one's own secrets always; the agent's when an owner of the agent turned them on
            return [null, (int) $m[1], true, $inherits['agent']];
        }
        if (preg_match('~^project:(\d+)$~', $context, $m) === 1) {
            $owner = ($this->projectOwner)((int) $m[1]) ?? 0;
            // the owner's consent is theirs: a project that changed hands inherits nothing of the new owner
            return [(int) $m[1], $owner, $inherits['owner'] && $inherits['by'] === $owner, $inherits['agent']];
        }
        return [null, 0, false, false];
    }
}
