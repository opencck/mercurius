<?php

declare(strict_types=1);

namespace OpenCCK\App\Mcp;

use OpenCCK\Domain\Mcp\McpInstall;

/**
 * An MCP of the delivery: a submodule `mcp/<name>/` built into the sandbox image with its dependencies, under
 * `/opt/mercurius/mcp/<name>/`. The index of the image names each: its package (the source is its repository on GitHub),
 * the revision — a print of its content made when the image was built — and the plan of its `mercurius.mcp.json`.
 *
 * spec: brief/skills-mcp.md#предустановленные-mcp-ssh-и-mail
 */
final class McpBundle {
    /** where the image keeps the MCPs of the delivery, and their index */
    public const DIR = '/opt/mercurius/mcp';
    public const INDEX = self::DIR . '/index.json';
    private const PACKAGE = '~^[a-z0-9][a-z0-9_.-]*/[a-z0-9][a-z0-9_.-]*$~';
    private const REVISION = '~^[0-9a-f]{12}$~';

    /** @param array<string, mixed> $entry the entry of the plan, with ${INSTALL_DIR} */
    public function __construct(
        public readonly string $name,
        public readonly string $source,
        public readonly string $revision,
        public readonly array $entry,
    ) {}

    public function dir(): string {
        return self::DIR . '/' . $this->name;
    }

    /**
     * The bundles of an index; one with a bad name, package, revision or entry is left out.
     *
     * @return list<self>
     */
    public static function fromIndex(string $json): array {
        /** @var mixed $index */
        $index = json_decode($json, true);
        $bundles = [];
        /** @var mixed $item */
        foreach (is_array($index) ? $index : [] as $item) {
            if (!is_array($item)) {
                continue;
            }
            $name = $item['name'] ?? null;
            $package = $item['package'] ?? null;
            $revision = $item['revision'] ?? null;
            $plan = isset($item['plan']) && is_array($item['plan']) ? $item['plan'] : [];
            $entry = isset($plan['entry']) && is_array($plan['entry']) ? $plan['entry'] : null;
            if (!is_string($name) || preg_match(McpInstall::NAME_PATTERN, $name) !== 1 || !is_string($package) || preg_match(self::PACKAGE, $package) !== 1
                || !is_string($revision) || preg_match(self::REVISION, $revision) !== 1 || $entry === null || !is_string($entry['command'] ?? null)) {
                continue;
            }
            /** @var array<string, mixed> $entry */
            $bundles[] = new self($name, 'https://github.com/' . $package, $revision, $entry);
        }
        return $bundles;
    }

    /** Whether an install's source is the repository of this bundle. */
    public function sameSource(string $source): bool {
        $plain = static fn(string $url): string => strtolower(preg_replace('~(\.git)?/?$~', '', $url) ?? $url);
        return $plain($source) === $plain($this->source);
    }
}
