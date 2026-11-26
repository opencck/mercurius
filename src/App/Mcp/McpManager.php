<?php

declare(strict_types=1);

namespace OpenCCK\App\Mcp;

use Mercurius\Mcp\McpClient;
use Amp\Cancellation;
use Amp\NullCancellation;
use Closure;
use DomainException;
use OpenCCK\App\Agent\Tool\ToolRegistry;
use OpenCCK\App\Vault\Vault;
use OpenCCK\Domain\Agent\DangerClass;
use OpenCCK\Domain\Entity\Member;
use OpenCCK\Domain\Mcp\McpServer;
use OpenCCK\Domain\Repository\McpServerRepository;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

use function Mercurius\I18n\t;

/**
 * Connections to MCP servers, each in its context: an approved connection's tools go into the registry for runs of
 * that context (a built-in server's for every run); the approved set of tools is hashed, and a server that changes its
 * names, descriptions or schemas is stopped (state changed) until it is approved again — a description is an
 * instruction the model reads. The state of every connection is kept in the store: /mcp, the metric and doctor read it.
 *
 * spec: brief/skills-mcp.md#три-слоя-установленный-mcp-запись-подключение — the states of a connection
 * spec: brief/skills-mcp.md#процессы-и-реестр-инструментов-по-контекстам — tools by context, names checked within it
 *
 * spec: brief/skills-mcp.md#mcp-серверы-подключение-и-доверие — servers from outside are untrusted until the owner approves them; a change of a tool's
 *       description is noticed; invisible Unicode is removed from descriptions and results
 * ref: references/hermes-agent/tools/mcp_tool.py@e96221fa — registration of server tools; here every server is an
 *      owner decision and a changed set disables it
 */
final class McpManager {
    private const DESCRIPTION_MAX = 1000;

    /** @var array<string, list<string>> connection key => tool names in the registry */
    private array $registered = [];
    /** @var array<string, list<string>> connection key => tool names taken in its context at the last sync */
    private array $taken = [];
    /** @var array<string, string> connection key => hash of the tools last kept in the cache of schemas */
    private array $kept = [];
    /** @var array<string, true> connections whose tools came from the cache: the first call lists them again */
    private array $fromCache = [];

    private ?McpSecrets $secrets = null;
    /** @var (Closure(string): bool)|null contexts whose connections come from a .mcp.json */
    private ?Closure $filed = null;
    /** @var (Closure(McpServer, string): ?string)|null the approved class of a tool of an installed MCP; null — not one */
    private ?Closure $installs = null;

    /**
     * @param Closure(string): ?Member $owner whose vault holds the token of a connection in this context
     * @param Closure(string): void $tell writes to the owner
     */
    public function __construct(
        private readonly McpServerRepository $servers,
        private readonly McpClient $client,
        private readonly Vault $vault,
        private readonly ToolRegistry $registry,
        private readonly Closure $owner,
        private readonly Closure $tell,
        private readonly LoggerInterface $logger,
        private readonly McpBreaker $breaker = new McpBreaker(),
        /** @var (Closure(): float)|null seconds now; null — the system clock */
        private readonly ?Closure $clock = null,
        /** stdio servers (`stdio:<command>`) run in the sandbox: off when there is no sandbox */
        private readonly bool $stdio = false,
    ) {}

    /** Pause before the first retry of a server that did not answer, doubled each time up to RETRY_MAX. */
    private const RETRY_FIRST = 30.0;
    private const RETRY_MAX = 600.0;
    /** an error that will not pass by itself waits a year: in effect, for the owner */
    private const LASTING = 31536000.0;
    /** How often the tools of connected servers are listed again: a change is caught without a restart. */
    private const RESYNC = 900.0;
    private const RESOURCE_MAX = 100_000;

    /** @var array<string, array{int, float}> connection keys that did not answer: tries so far and when to try again */
    private array $down = [];
    private ?float $lastResync = null;
    private bool $ticking = false;
    /** @var array<string, McpServer> built-in servers by name */
    private array $builtinServers = [];
    private ?\OpenCCK\App\Agent\Sandbox\Sandbox $sandbox = null;
    private ?OsvCheck $osv = null;

    /** spec: brief/skills-mcp.md#mcp-серверы-подключение-и-доверие — the check of MCP packages */
    /**
     * spec: brief/skills-mcp.md#установка-и-обновление — the tools of an installed MCP get the classes its approver set
     *
     * @param Closure(McpServer, string): ?string $classOf read, write or irreversible; null — not an installed MCP
     * @param (Closure(McpServer): bool)|null $allowedIn whether the installed code may run in the connection's context
     */
    public function useInstalls(Closure $classOf, ?Closure $allowedIn = null): void {
        $this->installs = $classOf;
        $this->allowedIn = $allowedIn;
    }

    /** @var (Closure(McpServer): bool)|null whether a connection may run the installed code it names in its context */
    private ?Closure $allowedIn = null;

    /**
     * The contexts whose connections come from a .mcp.json: there a server runs only by an approved entry.
     *
     * @param Closure(string): bool $filed
     */
    public function useFiles(Closure $filed): void {
        $this->filed = $filed;
    }

    /** @var (Closure(McpServer): list<string>)|null */
    private ?Closure $moreArgs = null;
    /** @var (Closure(McpServer, McpTool, string): \OpenCCK\App\Agent\Tool\Tool)|null */
    private ?Closure $wrapAny = null;

    /**
     * The core around our SSH MCP: arguments added at the start of a process (the host keys the owner trusted), and
     * the tools of a connection put into the registry through a wrapper (the command policy, the journal, the cards).
     *
     * spec: brief/servers.md#ssh-как-предустановленный-mcp
     *
     * @param (Closure(McpServer): list<string>)|null $moreArgs
     * @param (Closure(McpServer, McpTool, string): \OpenCCK\App\Agent\Tool\Tool)|null $wrap the tool, wrapped or as it is
     */
    public function useSsh(?Closure $moreArgs, ?Closure $wrap): void {
        $this->moreArgs = $moreArgs;
        $this->wrapAny = $wrap;
    }

    /**
     * A tool of a connection called by the core rather than by the model: the text and the structured content with the
     * values put into the entry masked.
     *
     * @param array<string, mixed> $args
     * @return array{string, bool, array<array-key, mixed>|null}
     * @throws DomainException the connection is not on, a secret it names is missing, or the server did not answer
     */
    public function call(string $context, string $name, string $tool, array $args, Cancellation $cancellation): array {
        $server = $this->servers->find($context, $name);
        if ($server === null || !$server->enabled() || ($server->approvedHash === null && $this->filed !== null && ($this->filed)($context))) {
            throw new DomainException(sprintf(t('mcp.mcp_manager.not_connected'), $name, '—'));
        }
        [$target, $token, $values] = $this->endpointOnCall($server);
        try {
            $answer = $this->client->call($target, $token, $tool, $args, $cancellation);
        } catch (RuntimeException $e) {
            throw new DomainException(self::hide($e->getMessage(), $values));
        }
        $structured = $answer[3] ?? null;
        if ($structured !== null) {
            /** @var mixed $value */
            foreach ($structured as $field => $value) {
                if (is_string($value)) {
                    $structured[$field] = self::hide($value, $values);
                }
            }
        }
        return [self::hide(self::clean($answer[0]), $values), $answer[1], $structured];
    }

    /** Puts the secrets an approved entry names into it at each start and call. */
    public function useSecrets(McpSecrets $secrets): void {
        $this->secrets = $secrets;
    }

    /**
     * The secrets an entry names that its context has no value for; empty — none missing, or no vault here.
     *
     * @return list<string>
     */
    public function missingSecrets(string $context, McpEntry $entry): array {
        return $this->secrets === null ? [] : $this->secrets->render($entry, $context)->missing;
    }

    public function useOsv(OsvCheck $osv): void {
        $this->osv = $osv;
    }
    private ?\DateTimeZone $zone = null;

    /**
     * spec: brief/skills-mcp.md#почта-встроенный-mcp-сервер — files of MCP tools go to and from the participants' sandbox
     */
    public function useSandbox(\OpenCCK\App\Agent\Sandbox\Sandbox $sandbox, \DateTimeZone $zone): void {
        $this->sandbox = $sandbox;
        $this->zone = $zone;
    }

    /** At start: the tools of every active server; a changed one is turned off. */
    /** @var array<string, string> bearer tokens of built-in servers by name */
    private array $builtin = [];
    /** @var array<string, \Closure(McpTool, string): \OpenCCK\App\Agent\Tool\Tool> what a built-in server puts around its tools, by server name */
    private array $wraps = [];

    /**
     * A server of this installation (the mail server in compose): its tools are ours, so they are registered without
     * the owner's approval; the token comes from the settings. $wrap puts the application's own tool around each tool
     * of the server (the mail server: the mailbox of the caller).
     *
     * @param (\Closure(McpTool, string): \OpenCCK\App\Agent\Tool\Tool)|null $wrap
     *
     * spec: brief/skills-mcp.md#почта-встроенный-mcp-сервер — the built-in mail server
     */
    public function connectBuiltin(McpServer $server, #[\SensitiveParameter] string $token, ?\Closure $wrap = null): void {
        $this->builtin[$server->name] = $token;
        if ($wrap !== null) {
            $this->wraps[$server->name] = $wrap;
        }
        $this->builtinServers[$server->name] = $server;
        try {
            $count = $this->sync($server, false, new NullCancellation());
            $this->logger->info('Built-in MCP server connected', ['server' => $server->name, 'tools' => $count]);
        } catch (Throwable $e) {
            $this->logger->warning('Built-in MCP server is not reachable', ['server' => $server->name, 'error' => $e::class]);
            $this->markDown($server, 0, $e);
        }
    }

    /** At start: every connection that is on; a changed one is stopped, one that does not answer waits for its next try. */
    public function connectAll(): void {
        foreach ($this->servers->all() as $server) {
            if (!$server->enabled()) {
                continue;
            }
            // a connection in error keeps its next try across a restart: it waits instead of being tried at once
            if ($server->status === McpServer::ERROR && $server->nextTry !== null && $server->nextTry > $this->now()) {
                $this->down[$server->key()] = [1, $server->nextTry];
                continue;
            }
            $this->connect($server);
        }
    }

    private function connect(McpServer $server): void {
        if ($this->fromCache($server)) {
            return;
        }
        try {
            $this->sync($server, false, new NullCancellation());
        } catch (Throwable $e) {
            $this->logger->warning('MCP server is not reachable', ['server' => $server->key(), 'error' => $e::class]);
            $this->markDown($server, 0, $e);
        }
    }

    /** @throws DomainException */
    public function add(string $context, string $name, string $url, ?string $secret): McpServer {
        if (preg_match(McpServer::NAME_PATTERN, $name) !== 1) {
            throw new DomainException(t('mcp.mcp_manager.imya_mcp_servera_strochnye_latinskie'));
        }
        $this->checkAddress($url);
        return $this->servers->add($context, $name, $url, $secret);
    }

    /**
     * Whether a server can be added here: an http(s) address, or a command when the sandbox runs stdio servers.
     *
     * @throws DomainException
     */
    public function checkAddress(string $url): void {
        if (str_starts_with($url, 'stdio:')) {
            // spec: brief/skills-mcp.md#mcp-серверы-подключение-и-доверие — a stdio server is a command run in the sandbox
            if (!$this->stdio) {
                throw new DomainException(t('mcp.mcp_manager.stdio_off'));
            }
            $command = trim(substr($url, 6));
            if ($command === '' || mb_strlen($command) > 1000 || preg_match('~[\x00-\x1F]~', $command) === 1) {
                throw new DomainException(t('mcp.mcp_manager.stdio_command'));
            }
        } elseif (preg_match('~^https?://\S+$~', $url) !== 1) {
            throw new DomainException(t('mcp.mcp_manager.adres_mcp_servera_http_s'));
        }
    }

    /**
     * The owner's approval: the current tools become the approved set.
     *
     * @return string what was registered
     */
    public function approve(string $context, string $name, Cancellation $cancellation): string {
        $server = $this->servers->find($context, $name) ?? throw new DomainException(sprintf(t('mcp.mcp_manager.mcp_servera_net'), $name));
        // spec: brief/skills-mcp.md#mcp-серверы-подключение-и-доверие — the package of an npx/uvx server is looked up in OSV before it starts
        $blocked = str_starts_with($server->url, 'stdio:') ? $this->osv?->check(substr($server->url, 6), $cancellation) : null;
        if ($blocked !== null) {
            $this->logger->warning('MCP server not approved: its package has a malware advisory', ['server' => $name]);
            return $blocked;
        }
        unset($this->kept[$server->key()]);
        try {
            $count = $this->sync($server, true, $cancellation);
        } catch (DomainException $e) {
            // spec: brief/skills-mcp.md#три-слоя-установленный-mcp-запись-подключение — not approved: an error with why
            $this->servers->update($context, $name, McpServer::ERROR, null, mb_substr($e->getMessage(), 0, 300));
            return $e->getMessage();
        }
        $taken = $this->taken[$server->key()] ?? [];
        if ($taken !== []) {
            return sprintf(t('mcp.mcp_manager.names_taken'), $name, implode(', ', $taken));
        }
        return sprintf(t('mcp.mcp_manager.mcp_server_odobren_instrumentov'), $name, $count);
    }

    public function disable(string $context, string $name, string $note = ''): bool {
        $note = $note === '' ? t('mcp.mcp_manager.disabled_by_owner') : $note;
        $key = $context === '' ? $name : $context . '|' . $name;
        $this->unregister($key);
        $this->forget($key);
        unset($this->down[$key]);
        return $this->servers->update($context, $name, McpServer::DISABLED, null, $note) !== null;
    }

    /**
     * Which tools of the server go into the registry; an active server is synced at once. The approved set (its hash)
     * covers every tool of the server, so filtering is not a change the owner has to approve again.
     *
     * @param string|null $include null — every tool; '' — none
     */
    public function filter(string $context, string $name, ?string $include, string $exclude, Cancellation $cancellation): McpServer {
        $server = $this->servers->setFilter($context, $name, $include, $exclude) ?? throw new DomainException(sprintf(t('mcp.mcp_manager.mcp_servera_net'), $name));
        if ($server->status === McpServer::CONNECTED) {
            $this->sync($server, false, $cancellation);
        }
        return $server;
    }

    /**
     * Takes a connection's tools out and puts it into a state with why, without removing it: an entry changed and waits
     * for approval, or cannot run.
     *
     * spec: brief/skills-mcp.md#файл-mcpjson-и-одобрение-записи — a new or changed entry gives no tools until approved
     */
    public function hold(string $context, string $name, string $state, string $reason): void {
        $key = $context . '|' . $name;
        $this->unregister($key);
        $this->forget($key);
        unset($this->down[$key]);
        $this->servers->update($context, $name, $state, null, $reason);
    }

    /** An approved connection that was off or whose names were taken: its tools are listed and registered again. */
    public function resume(string $context, string $name): void {
        $server = $this->servers->find($context, $name);
        if ($server !== null) {
            $this->connect($server);
        }
    }

    public function remove(string $context, string $name): bool {
        $key = $context === '' ? $name : $context . '|' . $name;
        $this->unregister($key);
        $this->forget($key);
        unset($this->down[$key]);
        return $this->servers->remove($context, $name);
    }

    /**
     * Called every half a minute: a server that did not answer is tried again after a growing pause, and every
     * RESYNC seconds the tools of connected servers are listed again, so a changed set is caught without a restart.
     * Our HTTP client keeps no stream open, so tools/list_changed notifications never arrive: this listing stands in.
     *
     * ref: references/hermes-agent/tools/mcp_tool_health.py@e96221fa:L99-L143 (_make_message_handler, _refresh_tools) —
     *      a refresh of the tools re-registers them; hermes does it on tools/list_changed over its open session
     * ref: references/hermes-agent/tools/mcp_tool_lifecycle.py@e96221fa:L110-L120 — per-server backoff of reconnects
     */
    public function tick(): void {
        // a slow server may hold a tick past the next one: they never overlap
        if ($this->ticking) {
            return;
        }
        $this->ticking = true;
        try {
            $this->check();
        } finally {
            $this->ticking = false;
        }
    }

    private function check(): void {
        $now = $this->now();
        foreach ($this->down as $key => [$tries, $next]) {
            if ($next > $now) {
                continue;
            }
            $server = $this->byKey($key);
            if ($server === null || (!isset($this->builtinServers[$key]) && !$server->enabled())) {
                unset($this->down[$key]);
                continue;
            }
            try {
                $count = $this->sync($server, false, new NullCancellation());
                unset($this->down[$key]);
                $this->logger->info('MCP server reconnected', ['server' => $key, 'tools' => $count, 'tries' => $tries + 1]);
            } catch (Throwable $e) {
                $this->markDown($server, $tries + 1, $e);
            }
        }
        if ($this->lastResync === null) {
            $this->lastResync = $now;
            return;
        }
        if ($now - $this->lastResync < self::RESYNC) {
            return;
        }
        $this->lastResync = $now;
        foreach ($this->connected() as $server) {
            if ($this->stopped($server)) {
                continue;
            }
            try {
                $this->sync($server, false, new NullCancellation());
            } catch (Throwable $e) {
                $this->logger->warning('MCP server is not reachable', ['server' => $server->key(), 'error' => $e::class]);
                $this->markDown($server, 0, $e);
            }
        }
    }

    /**
     * Resources of a connected server: uri, name, type, description.
     *
     * @return list<array{uri: string, name: string, description: string, mimeType: string}>
     * @throws DomainException
     */
    public function resources(?string $context, string $name, Cancellation $cancellation): array {
        $server = $this->connectedServer($context, $name);
        $values = [];
        try {
            [$target, $token, $values] = $this->endpoint($server);
            $list = $this->client->resources($target, $token, $cancellation);
        } catch (RuntimeException $e) {
            throw new DomainException(self::hide($e->getMessage(), $values));
        }
        return array_map(static fn(array $r): array => [
            'uri' => $r['uri'],
            'name' => self::clean(mb_substr($r['name'], 0, 200)),
            'description' => self::clean(mb_substr($r['description'], 0, self::DESCRIPTION_MAX)),
            'mimeType' => self::clean(mb_substr($r['mimeType'], 0, 100)),
        ], $list);
    }

    /** @throws DomainException */
    public function readResource(?string $context, string $name, string $uri, Cancellation $cancellation): string {
        $server = $this->connectedServer($context, $name);
        $values = [];
        try {
            [$target, $token, $values] = $this->endpoint($server);
            $text = $this->client->readResource($target, $token, $uri, $cancellation);
        } catch (RuntimeException $e) {
            throw new DomainException(self::hide($e->getMessage(), $values));
        }
        return mb_substr(self::hide(self::clean($text), $values), 0, self::RESOURCE_MAX);
    }

    /**
     * The server said its tools changed (a stdio server can, over its open pipe): they are listed again at once, and
     * a changed set turns the server off as at start.
     */
    public function toolsChanged(string $key): void {
        \Amp\async(function () use ($key): void {
            foreach ($this->connected() as $server) {
                if ($server->key() !== $key) {
                    continue;
                }
                try {
                    $this->sync($server, false, new NullCancellation());
                } catch (Throwable $e) {
                    $this->logger->warning('MCP tools were not listed again', ['server' => $key, 'error' => $e::class]);
                }
            }
        })->ignore();
    }

    private function forget(string $key): void {
        if ($this->client instanceof McpSessions) {
            $this->client->forget($key);
        }
    }

    /** A built-in server by its name, or a connection by context|name. */
    private function byKey(string $key): ?McpServer {
        if (isset($this->builtinServers[$key])) {
            return $this->builtinServers[$key];
        }
        $parts = explode('|', $key, 2);
        return count($parts) === 2 ? $this->servers->find($parts[0], $parts[1]) : null;
    }

    /** @return list<McpServer> servers whose tools are in the registry now */
    private function connected(): array {
        $connected = [];
        foreach (array_keys($this->registered) as $key) {
            $server = $this->byKey($key);
            if ($server !== null && (isset($this->builtinServers[$key]) || $server->status === McpServer::CONNECTED)) {
                $connected[] = $server;
            }
        }
        return $connected;
    }

    /**
     * A connected server a run may read: a built-in one, or a connection of the run's context.
     *
     * @throws DomainException
     */
    private function connectedServer(?string $context, string $name): McpServer {
        $reachable = array_values(array_filter($this->connected(), static fn(McpServer $s): bool => $s->context === '' || $s->context === $context));
        foreach ($reachable as $server) {
            if ($server->name === $name) {
                return $server;
            }
        }
        $names = array_map(static fn(McpServer $s): string => $s->name, $reachable);
        throw new DomainException(sprintf(t('mcp.mcp_manager.not_connected'), $name, $names === [] ? '—' : implode(', ', $names)));
    }

    /**
     * spec: brief/skills-mcp.md#mcp-серверы-подключение-и-доверие — a server that did not answer is tried again after a
     *       growing pause; the connection is in error with why and when
     */
    /**
     * An error that will not pass by itself: no such command (exit 127), a refused login (HTTP 401, 403), a native
     * module built for another Node. It is not tried again: the owner fixes it, then turns the entry on.
     *
     * spec: brief/skills-mcp.md#процессы-и-реестр-инструментов-по-контекстам
     * ref: references/hermes-agent/tools/mcp_tool_node_abi.py@e96221fa:L16-L18 (_ABI_MARKERS) — NODE_MODULE_VERSION and
     *      ERR_DLOPEN_FAILED; hermes then says how to rebuild the package, here the stderr tail goes into the reason
     */
    public static function lasting(Throwable $why, int $tries): bool {
        $message = $why->getMessage();
        // a refused login may be a passing block of a proxy in front of the server: lasting on the third time in a row
        if ($tries >= 2 && preg_match('~\bHTTP 40[13]\b~u', $message) === 1) {
            return true;
        }
        // exit code 127 in the words of the ended process, in any language of the texts
        return preg_match('~[(（]\D{0,8}127[)）]|NODE_MODULE_VERSION|ERR_DLOPEN_FAILED~u', $message) === 1;
    }

    private function markDown(McpServer $server, int $tries, ?Throwable $why = null): void {
        // a built-in server has no switch for its owner: it keeps being tried
        if ($why !== null && $server->context !== '' && self::lasting($why, $tries)) {
            unset($this->down[$server->key()]);
            $reason = mb_substr(\OpenCCK\App\Agent\Tool\Guard\SecretMasker::mask($why->getMessage()), 0, 300);
            // the next try is far away: neither a restart nor the minute pass over the file tries it; /mcp on or a
            // change of the entry does
            $this->servers->update($server->context, $server->name, McpServer::ERROR, null, $reason, $this->now() + self::LASTING);
            // spec: brief/skills-mcp.md#установка-и-обновление — a native module built for another Node is fixed by a build
            $abi = preg_match('~NODE_MODULE_VERSION|ERR_DLOPEN_FAILED~', $why->getMessage()) === 1;
            ($this->tell)(sprintf(t('mcp.mcp_manager.lasting'), $server->name, $server->context, $reason, $server->name) . ($abi ? ' ' . sprintf(t('mcp.mcp_manager.abi_hint'), $server->name) : ''));
            return;
        }
        $pause = min(self::RETRY_FIRST * (float) (2 ** min($tries, 10)), self::RETRY_MAX);
        $next = $this->now() + $pause;
        $this->down[$server->key()] = [$tries, $next];
        if ($server->context !== '') {
            $reason = $why === null ? '' : mb_substr(\OpenCCK\App\Agent\Tool\Guard\SecretMasker::mask($why->getMessage()), 0, 300);
            $this->servers->update($server->context, $server->name, McpServer::ERROR, null, $reason, $next);
        }
    }

    private function now(): float {
        return $this->clock === null ? microtime(true) : ($this->clock)();
    }

    /** @return list<string> tool names of a connection (context|name, or a built-in server's name) in the registry */
    public function tools(string $key): array {
        return $this->registered[$key] ?? [];
    }

    /** Text without Unicode tags, private-use and other invisible format characters. */
    public static function clean(string $text): string {
        return preg_replace('~[\x{E0000}-\x{E007F}]|[\p{Co}]|(?![\x{200D}])[\p{Cf}]~u', '', $text) ?? '';
    }

    /** @return int how many tools were registered */
    private function sync(McpServer $server, bool $approve, Cancellation $cancellation): int {
        // spec: brief/skills-mcp.md#файл-mcpjson-и-одобрение-записи — a connection of a context with a file is called only
        //       by the address of its approved entry; nothing else of it reaches the network or starts a command
        if (!$approve && $server->approvedHash === null && $server->context !== '' && $this->filed !== null && ($this->filed)($server->context)) {
            throw new DomainException(sprintf(t('mcp.mcp_manager.not_approved'), $server->name));
        }
        // spec: brief/skills-mcp.md#три-слоя-установленный-mcp-запись-подключение — installed code runs only where allowed
        if ($this->allowedIn !== null && !($this->allowedIn)($server)) {
            throw new DomainException(sprintf(t('mcp.install.not_allowed'), $server->name, $server->context));
        }
        [$target, $token, $values] = $this->endpoint($server);
        try {
            $tools = $this->client->tools($target, $token, $cancellation);
        } catch (RuntimeException $e) {
            throw new DomainException(self::hide($e->getMessage(), $values));
        }
        $cleaned = [];
        foreach ($tools as $tool) {
            $cleaned[] = ['name' => $tool['name'], 'description' => mb_substr(self::clean($tool['description']), 0, self::DESCRIPTION_MAX), 'schema' => $tool['schema'], 'annotations' => $tool['annotations']];
        }
        usort(
            $cleaned,
            /**
             * @param array{name: string, description: string, schema: array<array-key, mixed>, annotations: array<array-key, mixed>} $a
             * @param array{name: string, description: string, schema: array<array-key, mixed>, annotations: array<array-key, mixed>} $b
             */
            static fn(array $a, array $b): int => strcmp($a['name'], $b['name']),
        );
        $hash = self::toolsHash($cleaned);
        if (!$approve && $server->toolsHash !== null && !hash_equals($server->toolsHash, $hash)) {
            $this->unregister($server->key());
            $this->forget($server->key());
            if ($server->context !== '') {
                $this->servers->update($server->context, $server->name, McpServer::CHANGED, null, t('mcp.mcp_manager.nabor_instrumentov_izmenilsya'));
            }
            ($this->tell)(sprintf(t('mcp.mcp_manager.mcp_server_izmenil_instrumenty_imena'), $server->name, $server->name));
            return 0;
        }
        unset($this->fromCache[$server->key()]);
        if (self::cached($server) && ($this->kept[$server->key()] ?? null) !== $hash) {
            $this->servers->saveTools($server->context, $server->name, self::listedBy($server), $cleaned);
            $this->kept[$server->key()] = $hash;
        }
        return $this->register($server, $cleaned, $hash, $approve);
    }

    /**
     * Puts the tools of a connection in the registry of its context.
     *
     * @param list<array{name: string, description: string, schema: array<array-key, mixed>, annotations: array<array-key, mixed>}> $cleaned
     * @return int how many were registered
     */
    private function register(McpServer $server, array $cleaned, string $hash, bool $approve): int {
        $this->unregister($server->key());
        $names = [];
        $taken = [];
        foreach ($cleaned as $tool) {
            $name = self::toolName($server->name, $tool['name']);
            if (!$server->registers($tool['name'])) {
                continue;
            }
            try {
                $mcpTool = new McpTool($server, $tool['name'], $name, $tool['description'], $tool['schema'], $tool['annotations'], $this->client, fn(): array => $this->endpointOnCall($server), $this->breaker);
                if ($this->sandbox !== null && $this->zone !== null) {
                    $mcpTool->useSandbox($this->sandbox, $this->zone);
                }
                $class = $this->installs === null ? null : ($this->installs)($server, $tool['name']);
                if ($class !== null) {
                    $mcpTool->useClass(DangerClass::tryFrom($class) ?? DangerClass::Irreversible);
                }
                $wrap = $this->wraps[$server->name] ?? null;
                $wrapped = $wrap !== null ? $wrap($mcpTool, $tool['name']) : ($this->wrapAny === null || $server->context === '' ? $mcpTool : ($this->wrapAny)($server, $mcpTool, $tool['name']));
                if ($server->context === '') {
                    if ($this->registry->get($name) !== null) {
                        continue;
                    }
                    $this->registry->register($wrapped);
                } elseif (!$this->registry->registerIn($server->context, $wrapped)) {
                    $taken[] = $name;
                    continue;
                }
            } catch (\InvalidArgumentException $e) {
                // spec: brief/agent-loop.md#инструменты — a tool with a schema no model can call is left out
                $this->logger->warning('An MCP tool is not registered', ['server' => $server->key(), 'tool' => mb_substr($tool['name'], 0, 80), 'problem' => $e->getMessage()]);
                continue;
            }
            $names[] = $name;
        }
        $this->registered[$server->key()] = $names;
        $this->taken[$server->key()] = $taken;
        if ($taken !== []) {
            // spec: brief/skills-mcp.md#три-слоя-установленный-mcp-запись-подключение — a connection whose tools would take
            //       names already taken in its context is not turned on while they are taken
            $this->unregister($server->key());
            $this->servers->update($server->context, $server->name, McpServer::NAMES, $approve ? $hash : null, implode(', ', $taken));
            return 0;
        }
        if ($server->context !== '') {
            $this->servers->update($server->context, $server->name, McpServer::CONNECTED, $approve ? $hash : null, '');
        }
        return count($names);
    }

    /** @param list<array{name: string, description: string, schema: array<array-key, mixed>, annotations: array<array-key, mixed>}> $cleaned */
    private static function toolsHash(array $cleaned): string {
        return hash('sha256', json_encode($cleaned, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /** A process of the sandbox: its tools are kept, so a start of the application does not start it. */
    private static function cached(McpServer $server): bool {
        return $server->context !== '' && str_starts_with($server->url, 'stdio:');
    }

    /**
     * What the kept tools were listed by: the hash of the stored entry (the same while it is being approved and after),
     * or the command of a connection without one.
     */
    private static function listedBy(McpServer $server): string {
        if ($server->entry !== null) {
            try {
                return McpEntry::fromArray($server->name, $server->entry)->hash();
            } catch (\InvalidArgumentException) {
                // a stored entry that no longer reads: the command stands for it
            }
        }
        return $server->approvedHash ?? $server->url;
    }

    /**
     * Registers the kept tools of a stdio connection without starting its process. They are trusted only when they
     * were listed by the same entry and are the approved set; otherwise the process is started and asked.
     *
     * spec: brief/skills-mcp.md#процессы-и-реестр-инструментов-по-контекстам
     * ref: references/hermes-agent/tools/mcp_schema_cache.py@e96221fa:L27-L39 (config_fingerprint) — the cache is
     *      keyed by what defines the connection; here by the approved entry, and checked against the approved tools
     */
    private function fromCache(McpServer $server): bool {
        if (!self::cached($server) || $server->toolsHash === null) {
            return false;
        }
        if ($server->approvedHash === null && $this->filed !== null && ($this->filed)($server->context)) {
            return false;
        }
        $tools = $this->servers->cachedTools($server->context, $server->name, self::listedBy($server));
        if ($tools === null || !hash_equals($server->toolsHash, self::toolsHash($tools))) {
            return false;
        }
        try {
            // a secret the entry names is gone: the connection waits as it would after a start
            $this->endpoint($server);
        } catch (DomainException $e) {
            $this->markDown($server, 0, $e);
            return true;
        }
        $this->kept[$server->key()] = $server->toolsHash;
        $this->register($server, $tools, $server->toolsHash, false);
        $this->fromCache[$server->key()] = true;
        return true;
    }

    /** A stdio connection whose process is not running: listing its tools only to see them would start it. */
    private function stopped(McpServer $server): bool {
        return str_starts_with($server->url, 'stdio:') && $this->client instanceof McpSessions && $this->client->idle($server->key());
    }

    /**
     * The endpoint of a call. The first call to tools taken from the cache lists them again from the started process:
     * a change of the package is caught as at start.
     *
     * @return array{McpServer, ?string, list<string>}
     * @throws DomainException
     */
    private function endpointOnCall(McpServer $server): array {
        if (isset($this->fromCache[$server->key()])) {
            // the package is asked before the call goes to it; a failed check leaves the next call to check again
            try {
                $this->sync($server, false, new NullCancellation());
            } catch (Throwable $e) {
                throw new DomainException(sprintf(t('mcp.mcp_manager.not_checked'), $server->name) . $e->getMessage());
            }
            if (($this->registered[$server->key()] ?? []) === []) {
                throw new DomainException(t('mcp.mcp_manager.nabor_instrumentov_izmenilsya'));
            }
        }
        return $this->endpoint($server);
    }

    private function unregister(string $key): void {
        $server = $this->byKey($key);
        $names = $this->registered[$key] ?? [];
        if ($server !== null && $server->context !== '') {
            $this->registry->removeIn($server->context, ...$names);
        } else {
            $this->registry->remove(...$names);
        }
        unset($this->registered[$key]);
    }

    /**
     * The connection as a start or a call sees it, the token and the values to mask. A built-in server and a connection
     * kept before entries were stored go by the token of the vault; an approved entry goes with the secrets it names
     * put in from the layers of its context.
     *
     * spec: brief/skills-mcp.md#подстановка-секретов — a secret named but not there: the connection fails naming it
     * ref: references/hermes-agent/tools/mcp_tool_config.py@e96221fa:L602-L621 (_require_rendered_remote) — a remote
     *      server is not called with its credential missing (a sure 401 read as a bad key); here a local one neither
     *
     * @return array{McpServer, ?string, list<string>}
     * @throws DomainException
     */
    private function endpoint(McpServer $server): array {
        if ($server->context === '' || $server->entry === null || $this->secrets === null) {
            $token = $this->token($server);
            if ($token === null && $server->secret !== null && $server->context !== '') {
                throw new DomainException(sprintf(t('mcp.mcp_manager.secret_missing'), explode(':', $server->secret, 2)[0], $server->name));
            }
            return [$server, $token, []];
        }
        try {
            $entry = McpEntry::fromArray($server->name, $server->entry);
        } catch (\InvalidArgumentException $e) {
            throw new DomainException($e->getMessage());
        }
        $secrets = $this->secrets;
        $entry = $entry->withMoreArgs($this->moreArgs === null ? [] : ($this->moreArgs)($server));
        $rendered = $secrets->render($entry, $server->context);
        if ($rendered->missing !== []) {
            throw new DomainException(sprintf(t('mcp.mcp_manager.secret_missing'), implode(', ', $rendered->missing), $server->name));
        }
        return [$server->withEndpoint($rendered->address, $rendered->headers, $rendered->env, $rendered->hash), null, $rendered->values];
    }

    /**
     * A text from a server with the values put into its entry masked: a server may echo what it got.
     *
     * @param list<string> $values
     */
    public static function hide(string $text, #[\SensitiveParameter] array $values): string {
        $values = array_filter($values, static fn(string $v): bool => strlen($v) >= 4);
        // the longest first: a value inside another is not left half-shown
        usort($values, static fn(string $a, string $b): int => strlen($b) <=> strlen($a));
        return $values === [] ? $text : str_replace($values, '***', $text);
    }

    private function token(McpServer $server): ?string {
        // spec: brief/skills-mcp.md#подстановка-секретов — the token of a built-in server goes to it alone, never to a
        //       connection of a context that took its name
        if ($server->context === '' && isset($this->builtin[$server->name])) {
            return $this->builtin[$server->name];
        }
        if ($server->secret === null) {
            return null;
        }
        $owner = ($this->owner)($server->context);
        // `secret:VARIABLE` of a stdio server names the variable after the secret
        $secret = explode(':', $server->secret, 2)[0];
        $token = $owner === null ? null : $this->vault->reveal($secret, $owner);
        return $token === null ? null : trim($token);
    }

    public static function toolName(string $server, string $tool): string {
        $tool = strtolower(preg_replace('~[^A-Za-z0-9_]~', '_', $tool) ?? '');
        return mb_substr(sprintf('mcp_%s_%s', $server, $tool), 0, 64);
    }
}
