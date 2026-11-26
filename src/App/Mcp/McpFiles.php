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
 * member of the project may rewrite it, so an entry is a request: a server is called only by the address of an entry
 * whose hash is the approved one. A new, changed or refused entry waits, with no network and no command; an entry
 * turned off or taken out stops its connection; a file that cannot be read changes nothing. Commands write the file
 * themselves and the reading follows at once.
 *
 * spec: brief/skills-mcp.md#файл-mcpjson-и-одобрение-записи
 * ref: references/hermes-agent/tools/mcp_tool_config.py@e96221fa:L660-L687 (_filter_suspicious_mcp_servers) — the entry
 *      is checked again before it runs; here the approval by hash comes first, the check sorts out what cannot run
 */
final class McpFiles {
    /** the holder of the owners' area in the sandbox */
    public const OWNERS_AREA = \OpenCCK\App\Agent\Tool\Sandbox\SandboxTool::OWNERS_AREA;

    /** @var array<string, true> context|name of new entries already told about */
    private array $told = [];

    /**
     * @param Closure(int): ?SandboxLimits $limitsOf the sandbox of a member, null — none
     * @param Closure(int): ?int $projectOwner the member whose folder holds a project, null — no such project
     * @param Closure(string, McpEntry): void $tell a new or changed entry waits: the owners get a card
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
     * Whose folder holds the context's file and where in it: the owners' area (the root), a member's folder, or a
     * project's folder in its owner's; null — the context has no file.
     *
     * @return array{int, string}|null
     */
    public function where(string $context): ?array {
        // spec: brief/agent-loop.md#раскладка-каталогов — the owners' context keeps its file in the root
        if ($context === 'owners') {
            return [self::OWNERS_AREA, McpFile::NAME];
        }
        if (preg_match('~^member:(\d+)$~', $context, $m) === 1) {
            return [(int) $m[1], McpFile::NAME];
        }
        if (preg_match('~^project:(\d+)$~', $context, $m) === 1) {
            $holder = ($this->projectOwner)((int) $m[1]);
            return $holder === null ? null : [$holder, 'projects/' . $m[1] . '/' . McpFile::NAME];
        }
        return null;
    }

    /** Whether the context keeps its connections in a file: then they run only by an approved entry. */
    public function keeps(string $context): bool {
        $at = $this->where($context);
        return $at !== null && ($this->limitsOf)($at[0]) !== null;
    }

    /**
     * The file of the context: null — there is none; false — there is one, but it was not read.
     */
    private function load(string $context): McpFile|false|null {
        $at = $this->where($context);
        $limits = $at === null ? null : ($this->limitsOf)($at[0]);
        if ($at === null || $limits === null) {
            return null;
        }
        try {
            return McpFile::parse($this->sandbox->fetch($at[0], $limits, $at[1], McpFile::MAX_BYTES, new TimeoutCancellation(15)));
        } catch (Throwable) {
        }
        // not fetched: a file that is not in its folder is missing, one that is there was not read
        try {
            $folder = dirname($at[1]);
            foreach ($this->sandbox->list($at[0], $limits, $folder, 1, new TimeoutCancellation(15)) as $item) {
                if ($item['path'] === McpFile::NAME) {
                    return false;
                }
            }
        } catch (Throwable) {
            // no folder yet (a project not set up): no file in it either
        }
        return null;
    }

    /**
     * The file for a command to change; refused when it is there but not read, so a command never writes over what it
     * did not see. Null — there is no file yet.
     *
     * @throws InvalidArgumentException
     */
    private function readable(string $context): ?McpFile {
        $file = $this->load($context);
        if ($file === false || $file?->broken === true) {
            throw new InvalidArgumentException(t('mcp.files.unreadable'));
        }
        return $file;
    }

    /** @param array<array-key, mixed> $servers */
    private function write(string $context, array $servers): void {
        $at = $this->where($context);
        $limits = $at === null ? null : ($this->limitsOf)($at[0]);
        if ($at === null || $limits === null) {
            throw new InvalidArgumentException(t('mcp.files.no_place'));
        }
        $this->sandbox->write($at[0], $limits, $at[1], McpFile::render($servers), new TimeoutCancellation(15));
    }

    /**
     * The connections of the context brought to its file.
     *
     * @return array<string, McpEntry> the entries that wait for approval, by name
     */
    public function sync(string $context): array {
        if (!$this->keeps($context)) {
            // no folder to keep a file in (no sandbox for this member): the connections stay as they are
            return [];
        }
        $file = $this->load($context);
        $connections = [];
        foreach ($this->servers->inContext($context) as $server) {
            $connections[$server->name] = $server;
        }
        if ($file === null) {
            if ($connections !== []) {
                $this->adopt($context, $connections);
            }
            return [];
        }
        if ($file === false || $file->broken) {
            // spec: brief/skills-mcp.md#файл-mcpjson-и-одобрение-записи — a file not read says nothing of its entries
            $this->logger->notice('A .mcp.json is not read; its connections stay as they are', ['context' => $context, 'errors' => $file === false ? [] : $file->errors]);
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
                    $waiting[$name] = $entry;
                }
            } catch (Throwable $e) {
                $this->logger->warning('A .mcp.json entry was not brought in', ['context' => $context, 'entry' => $name, 'error' => $e::class]);
            }
        }
        foreach ($file->bad as $name => $why) {
            // an entry spoiled in the file stops its connection and keeps it, with why: fixed, it is the same entry
            $server = $connections[$name] ?? null;
            unset($connections[$name]);
            if ($server !== null) {
                $this->wait($context, $server, $why);
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
        $problem = $this->guard->problem($entry);
        if ($server === null) {
            // a new entry is kept with its address and waits: nothing of it is called before the approval
            [$url, $secret] = [$entry->address(), null];
            $server = $this->servers->add($context, $entry->name, $url, $secret);
        }
        if ($problem !== null) {
            $this->wait($context, $server, $problem);
            return false;
        }
        if ($server->approvedHash === null || !hash_equals($server->approvedHash, $entry->hash())) {
            // the address stays the approved one until the new entry is approved
            $this->wait($context, $server, $server->approvedHash === null ? '' : t('mcp.files.changed'));
            return !$entry->disabled;
        }
        if ($server->entry === null) {
            // approved before entries were kept: the approved entry is stored, so its secrets keep coming
            $server = $this->servers->setEntry($context, $entry->name, $entry->toArray()) ?? $server;
        }
        [$url, $secret] = [$entry->address(), null];
        if ($server->url !== $url || $server->secret !== $secret) {
            // the approved entry is the address: a connection kept before files had it written otherwise
            $server = $this->servers->setAddress($context, $entry->name, $url, $secret) ?? $server;
        }
        if ($entry->disabled) {
            if ($server->status !== McpServer::DISABLED) {
                $this->manager->disable($context, $entry->name, t('mcp.files.off_in_file'));
            }
            return false;
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

    /** The connection waits with why: its tools out, nothing of it called. */
    private function wait(string $context, McpServer $server, string $why): void {
        if ($server->status !== McpServer::PENDING || $server->note !== $why) {
            $this->manager->hold($context, $server->name, McpServer::PENDING, $why);
        }
    }

    /**
     * Connections kept before files existed: written to the context's file, and those approved by the old way (an
     * approved set of tools, no entry yet) approved as they were. A connection that waited keeps waiting.
     *
     * @param array<string, McpServer> $connections
     */
    private function adopt(string $context, array $connections): void {
        $entries = [];
        foreach ($connections as $name => $server) {
            try {
                $entries[$name] = self::entryOf($server);
            } catch (InvalidArgumentException $e) {
                $this->logger->warning('A connection cannot be written as a .mcp.json entry', ['context' => $context, 'server' => $name, 'error' => $e->getMessage()]);
            }
        }
        try {
            $this->write($context, $entries);
        } catch (Throwable $e) {
            $this->logger->warning('Connections were not written to .mcp.json', ['context' => $context, 'error' => $e::class]);
            return;
        }
        foreach ($entries as $name => $entry) {
            $server = $connections[$name];
            if ($server->toolsHash !== null && $server->approvedHash === null && $server->status !== McpServer::PENDING) {
                $this->servers->setApproval($context, $name, $entry->hash(), null, $entry->toArray());
            }
        }
        $this->logger->notice('Connections written to .mcp.json', ['context' => $context, 'count' => count($entries)]);
    }

    /** @throws InvalidArgumentException */
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

    /**
     * Each entry that waits gets one card, and a changed one a card of its own.
     *
     * spec: brief/skills-mcp.md#файл-mcpjson-и-одобрение-записи — a new or changed entry waits for approval; the owners hear of it
     *
     * @param array<string, McpEntry> $waiting
     */
    private function tellAbout(string $context, array $waiting): void {
        foreach ($waiting as $name => $entry) {
            $key = $context . '|' . $name . '|' . $entry->hash();
            if (isset($this->told[$key])) {
                continue;
            }
            $this->told[$key] = true;
            try {
                ($this->tell)($context, $entry);
            } catch (Throwable $e) {
                $this->logger->warning('The card of an MCP entry was not sent', ['context' => $context, 'entry' => $name, 'error' => $e::class]);
            }
        }
    }

    /** The entry the file of the context holds now under this name; null — none, or the file was not read. */
    public function current(string $context, string $name): ?McpEntry {
        $file = $this->load($context);
        return $file instanceof McpFile && !$file->broken ? ($file->entries[$name] ?? null) : null;
    }

    /** The entry is not approved: it keeps waiting, its tools out, with who refused it. */
    public function refuse(string $context, string $name, Member $by, ?McpEntry $entry = null): void {
        $server = $this->servers->find($context, $name);
        $approved = $server?->approvedHash;
        if ($entry !== null && $approved !== null && hash_equals($approved, $entry->hash())) {
            // the entry refused is the one approved: the approval is taken back, or the next reading would resume it
            $this->servers->setApproval($context, $name, null, null);
        }
        $this->manager->hold($context, $name, McpServer::PENDING, sprintf(t('mcp.cards.refused_reason'), $by->displayName ?? (string) $by->id));
    }

    /**
     * Approves the entry the file holds now, read once: the connection takes its address, its tools are listed, and
     * only when that worked its hash becomes the approved one. A refusal (a package with an advisory, no answer, a
     * secret missing) leaves nothing approved: the connection waits, with why.
     *
     * spec: brief/skills-mcp.md#файл-mcpjson-и-одобрение-записи — the hash of the approved entry and who approved it
     */
    public function approve(string $context, string $name, Member $by, ?string $expected = null): string {
        $file = $this->load($context);
        if ($file === false || $file?->broken === true) {
            return t('mcp.files.unreadable');
        }
        $entry = $file?->entries[$name] ?? null;
        if ($file === null || $entry === null) {
            return $file?->bad[$name] ?? sprintf(t('mcp.files.no_entry'), $name);
        }
        // a card approves the entry it showed: the file read here, not an earlier read, must hold it
        if ($expected !== null && !str_starts_with($entry->hash(), $expected)) {
            return t('mcp.cards.decided');
        }
        $problem = $this->guard->problem($entry);
        if ($problem !== null) {
            return $problem;
        }
        $approved = $this->servers->find($context, $name)?->approvedHash;
        if (!$entry->disabled && $approved !== null && hash_equals($approved, $entry->hash())) {
            // approved already as it is: another press (a card read twice, an old card) changes nothing
            return sprintf(t('mcp.files.already_approved'), $name);
        }
        if ($entry->disabled) {
            $entry = $entry->withDisabled(false);
            $servers = $file->servers;
            $servers[$name] = $entry;
            $this->write($context, $servers);
        }
        [$url, $secret] = [$entry->address(), null];
        $server = $this->servers->find($context, $name) ?? $this->servers->add($context, $name, $url, $secret);
        if ($server->url !== $url || $server->secret !== $secret) {
            $this->servers->setAddress($context, $name, $url, $secret);
        }
        // the entry being approved is what the trial calls, its secrets put in; its hash is not approved until it
        // worked, so nothing else calls it meanwhile
        $this->servers->setApproval($context, $name, null, null, $entry->toArray());
        $answer = $this->manager->approve($context, $name, new TimeoutCancellation(60));
        $after = $this->servers->find($context, $name);
        // a missing secret is what the entry waits for, not a reason to refuse it: approved, in error with its name
        $missing = $this->manager->missingSecrets($context, $entry);
        if ($after !== null && (in_array($after->status, [McpServer::CONNECTED, McpServer::NAMES], true) || $missing !== [])) {
            $this->servers->setApproval($context, $name, $entry->hash(), $by->id, $entry->toArray());
        } elseif ($after !== null) {
            $this->servers->setApproval($context, $name, null, null);
            $this->manager->hold($context, $name, McpServer::PENDING, mb_substr($answer, 0, 300));
        }
        return $answer;
    }

    /**
     * Adds or replaces an entry of the context's file and reads the file again; without reading it again the entry
     * waits for an approval the caller gives at once (an entry the application itself moved there), with no card.
     *
     * @throws InvalidArgumentException the file is there but was not read, or there is no place for it
     */
    /**
     * The agent writes an entry into the file of a context — of the caller, of a project they own, the owners' for an
     * owner of the agent — and it waits for its card, as any new or changed entry.
     *
     * spec: brief/skills-mcp.md#файл-mcpjson-и-одобрение-записи
     *
     * @param array<array-key, mixed> $raw the entry: command, args, env with {SECRET} names, or url and headers
     * @return string what happened, for the model
     */
    public function request(string $context, string $name, array $raw, Member $who): string {
        if (!$this->mayWrite($context, $who)) {
            return t('service.mcp_commands.not_yours');
        }
        try {
            $entry = McpEntry::fromArray($name, $raw);
        } catch (InvalidArgumentException $e) {
            return $e->getMessage();
        }
        $problem = $this->guard->problem($entry);
        if ($problem !== null) {
            return $problem;
        }
        try {
            $this->put($context, $entry);
        } catch (InvalidArgumentException $e) {
            return $e->getMessage();
        }
        return sprintf(t('mcp.files.entry_written'), $name, $context);
    }

    /** A member writes into their own file, a project owner into the project's, an owner of the agent into any. */
    private function mayWrite(string $context, Member $who): bool {
        if ($who->isOwner()) {
            return true;
        }
        [$kind, $id] = array_pad(explode(':', $context, 2), 2, '');
        return match ($kind) {
            'member' => (int) $id === $who->id,
            'project' => ($this->projectOwner)((int) $id) === $who->id,
            default => false,
        };
    }

    /**
     * Writes an entry of our own code that needs no card — a sample of an MCP of the delivery — approved at once
     * without a trial: it may wait for its secrets, and then it is in error with their names, not on a card.
     *
     * spec: brief/skills-mcp.md#предустановленные-mcp-ssh-и-mail
     *
     * @throws InvalidArgumentException
     */
    public function accept(string $context, McpEntry $entry, Member $by): void {
        $this->put($context, $entry, false);
        $url = $entry->address();
        $server = $this->servers->find($context, $entry->name) ?? $this->servers->add($context, $entry->name, $url, null);
        if ($server->url !== $url) {
            $this->servers->setAddress($context, $entry->name, $url, null);
        }
        $this->servers->setApproval($context, $entry->name, $entry->hash(), $by->id, $entry->toArray());
        $this->sync($context);
    }

    public function put(string $context, McpEntry $entry, bool $sync = true): void {
        $servers = $this->readable($context)->servers ?? [];
        $servers[$entry->name] = $entry;
        $this->write($context, $servers);
        if ($sync) {
            $this->sync($context);
        }
    }

    /**
     * Takes an entry out of the context's file and reads the file again; false — there was none.
     *
     * @throws InvalidArgumentException
     */
    public function drop(string $context, string $name): bool {
        $servers = $this->readable($context)->servers ?? [];
        if (!array_key_exists($name, $servers)) {
            return false;
        }
        unset($servers[$name]);
        $this->write($context, $servers);
        $this->sync($context);
        return true;
    }

    /**
     * Turns an entry of the context's file off or on and reads the file again; false — there is none.
     *
     * @throws InvalidArgumentException
     */
    public function switch(string $context, string $name, bool $on): bool {
        $entry = $this->readable($context)?->entries[$name] ?? null;
        if ($entry === null) {
            return false;
        }
        $this->put($context, $entry->withDisabled(!$on));
        return true;
    }
}
