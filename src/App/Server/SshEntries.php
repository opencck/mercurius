<?php

declare(strict_types=1);

namespace OpenCCK\App\Server;

use Closure;
use OpenCCK\Domain\Mcp\McpServer;
use OpenCCK\Domain\Repository\McpServerRepository;
use OpenCCK\Domain\Repository\SshHostKeyRepository;

/**
 * The SSH entries of .mcp.json that run our MCP: known by the installed code an entry starts (a path under
 * /workspace/mcp/<install>/ of an install from our repository), not by the name of the entry — a third-party SSH MCP
 * gets no wrapper. A server of an entry is its arguments; the host key the owner trusted goes to its process.
 *
 * spec: brief/servers.md#ssh-как-предустановленный-mcp
 */
final class SshEntries {
    /** where our MCP comes from: its repository, or its copy in the delivery */
    public const SOURCE = '~^https://github\.com/opencck/mercurius-mcp-ssh(?:\.git)?/?$~i';
    private const CODE = '~^/workspace/mcp/([a-z0-9][a-z0-9_]{0,23})/~';

    /** @param Closure(string): ?string $sourceOf the source of an install by its name; null — no such install */
    public function __construct(
        private readonly McpServerRepository $servers,
        private readonly Closure $sourceOf,
        private readonly SshHostKeyRepository $keys,
    ) {}

    /** The server of a connection that runs our MCP; null — another MCP, or no server named. */
    public function target(McpServer $server): ?SshTarget {
        $entry = $server->entry;
        if ($entry === null || $server->context === '' || !$this->ours($entry)) {
            return null;
        }
        $values = [];
        /** @var mixed $arg */
        foreach (is_array($entry['args'] ?? null) ? $entry['args'] : [] as $arg) {
            if (is_string($arg) && preg_match('~^--([a-z-]+)=(.*)$~s', $arg, $m) === 1) {
                $values[$m[1]] = $m[2];
            }
        }
        $host = $values['host'] ?? '';
        $user = $values['user'] ?? '';
        if ($host === '' || $user === '') {
            return null;
        }
        $jumpHost = ($values['jump-host'] ?? '') === '' ? null : $values['jump-host'];
        return new SshTarget(
            $server->key(),
            $server->context,
            $server->name,
            $host,
            (int) ($values['port'] ?? 22) ?: 22,
            $user,
            ($values['group'] ?? '') === '' ? 'default' : $values['group'],
            $jumpHost,
            (int) ($values['jump-port'] ?? 22) ?: 22,
            $jumpHost === null ? null : ($values['jump-user'] ?? null),
        );
    }

    /** @param array<array-key, mixed> $entry */
    public function ours(array $entry): bool {
        if (($entry['command'] ?? null) !== 'php') {
            return false;
        }
        /** @var mixed $arg */
        foreach (is_array($entry['args'] ?? null) ? $entry['args'] : [] as $arg) {
            if (is_string($arg) && preg_match(self::CODE, $arg, $m) === 1) {
                return preg_match(self::SOURCE, ($this->sourceOf)($m[1]) ?? '') === 1;
            }
        }
        return false;
    }

    /**
     * The entry a call names: in the context of the run first, then among the caller's own — the servers a member keeps
     * in their file follow them into a project's chat, as the registry of servers did. `context|name` names it exactly.
     */
    public function find(string $name, int $principalId, ?string $context): ?SshTarget {
        $name = mb_strtolower(trim($name));
        foreach ($this->visible($principalId, $context) as $target) {
            if ($target->name === $name || $target->key === $name) {
                return $target;
            }
        }
        return null;
    }

    /** @return list<SshTarget> the entries a member reaches from a run: of its context, then their own */
    public function visible(int $principalId, ?string $context): array {
        $own = 'member:' . $principalId;
        $found = [];
        foreach (array_values(array_unique(array_filter([$context, $own]))) as $at) {
            foreach ($this->servers->inContext($at) as $server) {
                $target = $server->enabled() ? $this->target($server) : null;
                if ($target !== null) {
                    $found[] = $target;
                }
            }
        }
        return $found;
    }

    /** @return list<SshTarget> every SSH entry of our MCP that is on, whoever keeps it */
    public function all(): array {
        $found = [];
        foreach ($this->servers->all() as $server) {
            $target = $server->enabled() ? $this->target($server) : null;
            if ($target !== null) {
                $found[] = $target;
            }
        }
        return $found;
    }

    /** @return list<SshTarget> every entry with this name, whoever keeps it: a read on any of a confirmed group waits */
    public function named(string $name): array {
        $name = mb_strtolower(trim($name));
        $found = [];
        foreach ($this->servers->all() as $server) {
            $target = $server->enabled() && ($server->name === $name || $server->key() === $name) ? $this->target($server) : null;
            if ($target !== null) {
                $found[] = $target;
            }
        }
        return $found;
    }

    /** A word for the shell, single-quoted. */
    public static function quote(string $text): string {
        return "'" . str_replace("'", "'\''", $text) . "'";
    }

    /**
     * The trusted host keys as arguments of the process: another key is another start.
     *
     * @return list<string>
     */
    public function keyArgs(McpServer $server): array {
        $target = $this->target($server);
        if ($target === null) {
            return [];
        }
        $args = [];
        $key = $this->keys->of($target->key, $target->address())?->hostKey;
        if ($key !== null) {
            $args[] = '--host-key=' . $key;
        }
        $jump = $target->jumpAddress();
        $jumpKey = $jump === null ? null : $this->keys->of($target->key, $jump)?->hostKey;
        if ($jumpKey !== null) {
            $args[] = '--jump-host-key=' . $jumpKey;
        }
        return $args;
    }
}
