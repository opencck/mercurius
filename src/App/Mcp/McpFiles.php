<?php

declare(strict_types=1);

namespace OpenCCK\App\Mcp;

use Amp\TimeoutCancellation;
use Closure;
use InvalidArgumentException;
use OpenCCK\App\Agent\Sandbox\Sandbox;
use OpenCCK\Domain\Agent\SandboxLimits;
use OpenCCK\Domain\Entity\Member;
use OpenCCK\Domain\Mcp\McpServer;
use OpenCCK\Domain\Repository\McpServerRepository;
use Psr\Log\LoggerInterface;
use Throwable;

use function Mercurius\I18n\t;

/**
 * The .mcp.json of a context and its connections. The file lies in the sandbox, where a command of the agent or a
 * member of the project may rewrite it, so an entry is a request: it runs only while its hash is the approved one. A
 * new or changed entry waits for approval, an entry the guard refuses is an error, an entry turned off or taken out
 * stops its connection. Commands write the file themselves and the reading follows at once.
 *
 * spec: brief/skills-mcp.md#файл-mcpjson-и-одобрение-записи
 * ref: references/hermes-agent/tools/mcp_tool_config.py@e96221fa:L660-L687 (_filter_suspicious_mcp_servers) — the entry
 *      is checked again before it runs; here the approval by hash comes first, the check sorts out what cannot run
 */
final class McpFiles {
    /** @var array<string, true> context|name of new entries already told about */
    private array $told = [];

    /**
     * @param Closure(int): ?SandboxLimits $limitsOf the sandbox of a member, null — none
     * @param Closure(int): ?int $projectOwner the member whose folder holds a project, null — no such project
     * @param Closure(string): void $tell writes to the owners
     */
    public function __construct(
        private readonly Sandbox $sandbox,
        private readonly Closure $limitsOf,
        private readonly McpServerRepository $servers,
        private readonly McpManager $manager,
        private readonly McpEntryGuard $guard,
        private readonly Closure $projectOwner,
        private readonly Closure $tell,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Whose folder holds the context's file and where in it; null — the context has no file now (the owners' area
     * comes with the root of the sandbox).
     *
     * @return array{int, string}|null
     */
    public function where(string $context): ?array {
        if (preg_match('~^member:(\d+)$~', $context, $m) === 1) {
            return [(int) $m[1], McpFile::NAME];
        }
        if (preg_match('~^project:(\d+)$~', $context, $m) === 1) {
            $holder = ($this->projectOwner)((int) $m[1]);
            return $holder === null ? null : [$holder, 'projects/' . $m[1] . '/' . McpFile::NAME];
        }
        return null;
    }

    /** The file of the context; null — no file there (or no sandbox to read it from). */
    public function read(string $context): ?McpFile {
        $at = $this->where($context);
        $limits = $at === null ? null : ($this->limitsOf)($at[0]);
        if ($at === null || $limits === null) {
            return null;
        }
        try {
            return McpFile::parse($this->sandbox->fetch($at[0], $limits, $at[1], McpFile::MAX_BYTES, new TimeoutCancellation(15)));
        } catch (Throwable) {
            return null;
        }
    }

    /** @param array<string, McpEntry> $entries */
    private function write(string $context, array $entries): void {
        $at = $this->where($context);
        $limits = $at === null ? null : ($this->limitsOf)($at[0]);
        if ($at === null || $limits === null) {
            throw new InvalidArgumentException(t('mcp.files.no_place'));
        }
        $this->sandbox->write($at[0], $limits, $at[1], McpFile::render($entries), new TimeoutCancellation(15));
    }

    /**
     * The connections of the context brought to its file.
     *
     * @return list<string> names of the entries that wait for approval
     */
    public function sync(string $context): array {
        $at = $this->where($context);
        if ($at === null || ($this->limitsOf)($at[0]) === null) {
            // no folder to keep a file in (no sandbox for this member): the connections stay as they are
            return [];
        }
        $file = $this->read($context);
        $connections = [];
        foreach ($this->servers->inContext($context) as $server) {
            $connections[$server->name] = $server;
        }
        if ($file === null) {
            if ($connections !== [] && $this->where($context) !== null) {
                $this->adopt($context, $connections);
            }
            return [];
        }
        foreach ($file->errors as $error) {
            $this->logger->notice('A .mcp.json entry is not read', ['context' => $context, 'error' => $error]);
        }
        $waiting = [];
        foreach ($file->entries as $name => $entry) {
            $server = $connections[$name] ?? null;
            unset($connections[$name]);
            try {
                if ($this->reconcile($context, $entry, $server)) {
                    $waiting[] = $name;
                }
            } catch (Throwable $e) {
                $this->logger->warning('A .mcp.json entry was not brought in', ['context' => $context, 'entry' => $name, 'error' => $e::class]);
            }
        }
        foreach (array_keys($connections) as $name) {
            // spec: brief/skills-mcp.md#файл-mcpjson-и-одобрение-записи — an entry taken out of the file turns it off
            $this->manager->remove($context, $name);
        }
        $this->tellAbout($context, $waiting);
        return $waiting;
    }

    /** @return bool whether the entry waits for approval */
    private function reconcile(string $context, McpEntry $entry, ?McpServer $server): bool {
        [$url, $secret] = $entry->connection();
        $problem = $entry->problem() ?? $this->guard->problem($entry);
        if ($server === null) {
            // the entry was checked when it was read: the connection is kept even if it cannot run, with why
            $server = $this->servers->add($context, $entry->name, $url, $secret);
        } elseif ($server->url !== $url || $server->secret !== $secret) {
            $server = $this->servers->setAddress($context, $entry->name, $url, $secret) ?? $server;
        }
        if ($problem !== null) {
            if ($server->status !== McpServer::ERROR || $server->note !== $problem) {
                $this->manager->hold($context, $entry->name, McpServer::ERROR, $problem);
            }
            return false;
        }
        if ($entry->disabled) {
            if ($server->status !== McpServer::DISABLED) {
                $this->manager->disable($context, $entry->name, t('mcp.files.off_in_file'));
            }
            return false;
        }
        if ($server->approvedHash === null || !hash_equals($server->approvedHash, $entry->hash())) {
            if ($server->status !== McpServer::PENDING) {
                $this->manager->hold($context, $entry->name, McpServer::PENDING, $server->approvedHash === null ? '' : t('mcp.files.changed'));
            }
            return true;
        }
        // approved and as it was: on again, a clash of names tried again, an error left to its next try
        // (a changed set of tools waits for /mcp on, as before)
        if (in_array($server->status, [McpServer::DISABLED, McpServer::PENDING, McpServer::NAMES], true)) {
            $this->manager->resume($context, $entry->name);
        } elseif ($server->status === McpServer::ERROR && $server->nextTry === null) {
            $this->manager->resume($context, $entry->name);
        }
        return false;
    }

    /**
     * Connections kept before files existed: written to the context's file once, approved as they were.
     *
     * @param array<string, McpServer> $connections
     */
    private function adopt(string $context, array $connections): void {
        $entries = [];
        foreach ($connections as $name => $server) {
            $entries[$name] = self::entryOf($server);
        }
        try {
            $this->write($context, $entries);
        } catch (Throwable $e) {
            $this->logger->warning('Connections were not written to .mcp.json', ['context' => $context, 'error' => $e::class]);
            return;
        }
        foreach ($connections as $name => $server) {
            if ($server->status !== McpServer::PENDING) {
                $this->servers->setApproval($context, $name, $entries[$name]->hash(), null);
            }
        }
        $this->logger->notice('Connections written to .mcp.json', ['context' => $context, 'count' => count($entries)]);
    }

    private static function entryOf(McpServer $server): McpEntry {
        $disabled = $server->status === McpServer::DISABLED;
        if (str_starts_with($server->url, 'stdio:')) {
            $words = preg_split('~\s+~', trim(substr($server->url, 6))) ?: [];
            [$secret, $variable] = array_pad(explode(':', (string) $server->secret, 2), 2, 'MCP_TOKEN');
            return McpEntry::fromArray($server->name, [
                'command' => $words[0] ?? 'true',
                'args' => array_map(static fn(string $w): string => trim($w, "'\""), array_slice($words, 1)),
                'env' => $server->secret === null ? [] : [$variable => '{' . $secret . '}'],
                'disabled' => $disabled,
            ]);
        }
        return McpEntry::fromArray($server->name, [
            'url' => $server->url,
            'headers' => $server->secret === null ? [] : ['Authorization' => 'Bearer {' . $server->secret . '}'],
            'disabled' => $disabled,
        ]);
    }

    /** @param list<string> $waiting */
    private function tellAbout(string $context, array $waiting): void {
        if (!str_starts_with($context, 'project:')) {
            return;
        }
        $new = array_values(array_filter($waiting, fn(string $name): bool => !isset($this->told[$context . '|' . $name])));
        if ($new === []) {
            return;
        }
        foreach ($new as $name) {
            $this->told[$context . '|' . $name] = true;
        }
        // spec: brief/skills-mcp.md#файл-mcpjson-и-одобрение-записи — in a project MCP is off until allowed: the owners hear of it
        ($this->tell)(sprintf(t('mcp.files.project_waiting'), $context, implode(', ', $new), $context, $new[0]));
    }

    /**
     * Approves the entry the file holds now: its hash becomes the approved one, then its tools are listed and registered.
     *
     * spec: brief/skills-mcp.md#файл-mcpjson-и-одобрение-записи — the hash of the approved entry and who approved it
     */
    public function approve(string $context, string $name, Member $by): string {
        $entry = $this->read($context)->entries[$name] ?? null;
        if ($entry === null) {
            return sprintf(t('mcp.files.no_entry'), $name);
        }
        $problem = $entry->problem() ?? $this->guard->problem($entry);
        if ($problem !== null) {
            return $problem;
        }
        if ($entry->disabled) {
            $this->put($context, $entry->withDisabled(false));
        }
        $this->sync($context);
        $this->servers->setApproval($context, $name, $entry->hash(), $by->id);
        return $this->manager->approve($context, $name, new TimeoutCancellation(60));
    }

    /** Adds or replaces an entry of the context's file and reads the file again. */
    public function put(string $context, McpEntry $entry): void {
        $entries = $this->read($context)->entries ?? [];
        $entries[$entry->name] = $entry;
        $this->write($context, $entries);
        $this->sync($context);
    }

    /** Takes an entry out of the context's file and reads the file again; false — there was none. */
    public function drop(string $context, string $name): bool {
        $entries = $this->read($context)->entries ?? [];
        if (!isset($entries[$name])) {
            return false;
        }
        unset($entries[$name]);
        $this->write($context, $entries);
        $this->sync($context);
        return true;
    }

    /** Turns an entry of the context's file off or on and reads the file again; false — there is none. */
    public function switch(string $context, string $name, bool $on): bool {
        $entry = $this->read($context)->entries[$name] ?? null;
        if ($entry === null) {
            return false;
        }
        $this->put($context, $entry->withDisabled(!$on));
        return true;
    }
}
