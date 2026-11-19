<?php

declare(strict_types=1);

namespace OpenCCK\App\Mcp;

use Amp\Cancellation;
use Amp\NullCancellation;
use Closure;
use DomainException;
use OpenCCK\App\Agent\Tool\ToolRegistry;
use OpenCCK\App\Vault\Vault;
use OpenCCK\Domain\Entity\Principal;
use OpenCCK\Domain\Mcp\McpServer;
use OpenCCK\Domain\Repository\McpServerRepository;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

use function Mercurius\I18n\t;

/**
 * External MCP servers: the owner adds and approves a server, its tools go into the registry; the approved set of
 * tools is hashed, and a server that changes its names, descriptions or schemas is turned off until the owner
 * approves it again — a description is an instruction the model reads.
 *
 * spec: BRIEF.md#скиллы-и-mcp — servers from outside are untrusted until the owner approves them; a change of a tool's
 *       description is noticed; invisible Unicode is removed from descriptions and results
 * ref: references/hermes-agent/tools/mcp_tool.py@e96221fa — registration of server tools; here every server is an
 *      owner decision and a changed set disables it
 */
final class McpManager {
    private const DESCRIPTION_MAX = 1000;

    /** @var array<string, list<string>> server => tool names in the registry */
    private array $registered = [];

    /**
     * @param Closure(): ?Principal $owner the owner, whose vault holds the servers' tokens
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
    private const RETRY_MAX = 900.0;
    /** How often the tools of connected servers are listed again: a change is caught without a restart. */
    private const RESYNC = 900.0;
    private const RESOURCE_MAX = 100_000;

    /** @var array<string, array{int, float}> servers that did not answer: tries so far and when to try again */
    private array $down = [];
    private ?float $lastResync = null;
    private bool $ticking = false;
    /** @var array<string, McpServer> built-in servers by name */
    private array $builtinServers = [];
    private ?\OpenCCK\App\Agent\Sandbox\Sandbox $sandbox = null;
    private ?OsvCheck $osv = null;

    /** spec: BRIEF.md#скиллы-и-mcp — the check of MCP packages */
    public function useOsv(OsvCheck $osv): void {
        $this->osv = $osv;
    }
    private ?\DateTimeZone $zone = null;

    /**
     * spec: BRIEF.md#интернет-и-браузер — files of MCP tools go to and from the participants' sandbox
     */
    public function useSandbox(\OpenCCK\App\Agent\Sandbox\Sandbox $sandbox, \DateTimeZone $zone): void {
        $this->sandbox = $sandbox;
        $this->zone = $zone;
    }

    /** At start: the tools of every active server; a changed one is turned off. */
    /** @var array<string, string> bearer tokens of built-in servers by name */
    private array $builtin = [];

    /**
     * A server of this installation (the mail server in compose): its tools are ours, so they are registered without
     * the owner's approval; the token and the headers come from the settings.
     *
     * spec: BRIEF.md#скиллы-и-mcp — the built-in mail server
     */
    public function connectBuiltin(McpServer $server, #[\SensitiveParameter] string $token): void {
        $this->builtin[$server->name] = $token;
        $this->builtinServers[$server->name] = $server;
        try {
            $count = $this->sync($server, false, new NullCancellation());
            $this->logger->info('Built-in MCP server connected', ['server' => $server->name, 'tools' => $count]);
        } catch (Throwable $e) {
            $this->logger->warning('Built-in MCP server is not reachable', ['server' => $server->name, 'error' => $e::class]);
            $this->markDown($server->name, 0);
        }
    }

    public function connectAll(): void {
        foreach ($this->servers->all() as $server) {
            if ($server->status === McpServer::ACTIVE) {
                try {
                    $this->sync($server, false, new NullCancellation());
                } catch (Throwable $e) {
                    $this->logger->warning('MCP server is not reachable', ['server' => $server->name, 'error' => $e::class]);
                    $this->markDown($server->name, 0);
                }
            }
        }
    }

    /** @throws DomainException */
    public function add(string $name, string $url, ?string $secret): McpServer {
        if (preg_match(McpServer::NAME_PATTERN, $name) !== 1) {
            throw new DomainException(t('mcp.mcp_manager.imya_mcp_servera_strochnye_latinskie'));
        }
        if (str_starts_with($url, 'stdio:')) {
            // spec: BRIEF.md#скиллы-и-mcp — a stdio server is a command run in the sandbox
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
        return $this->servers->add($name, $url, $secret);
    }

    /**
     * The owner's approval: the current tools become the approved set.
     *
     * @return string what was registered
     */
    public function approve(string $name, Cancellation $cancellation): string {
        $server = $this->servers->find($name) ?? throw new DomainException(sprintf(t('mcp.mcp_manager.mcp_servera_net'), $name));
        // spec: BRIEF.md#скиллы-и-mcp — the package of an npx/uvx server is looked up in OSV before it starts
        $blocked = str_starts_with($server->url, 'stdio:') ? $this->osv?->check(substr($server->url, 6), $cancellation) : null;
        if ($blocked !== null) {
            $this->logger->warning('MCP server not approved: its package has a malware advisory', ['server' => $name]);
            return $blocked;
        }
        $count = $this->sync($server, true, $cancellation);
        return sprintf(t('mcp.mcp_manager.mcp_server_odobren_instrumentov'), $name, $count);
    }

    public function disable(string $name, string $note = ''): bool {
        $note = $note === '' ? t('mcp.mcp_manager.disabled_by_owner') : $note;
        $this->unregister($name);
        $this->forget($name);
        return $this->servers->update($name, McpServer::DISABLED, null, $note) !== null;
    }

    /**
     * Which tools of the server go into the registry; an active server is synced at once. The approved set (its hash)
     * covers every tool of the server, so filtering is not a change the owner has to approve again.
     *
     * @param string|null $include null — every tool; '' — none
     */
    public function filter(string $name, ?string $include, string $exclude, Cancellation $cancellation): McpServer {
        $server = $this->servers->setFilter($name, $include, $exclude) ?? throw new DomainException(sprintf(t('mcp.mcp_manager.mcp_servera_net'), $name));
        if ($server->status === McpServer::ACTIVE) {
            $this->sync($server, false, $cancellation);
        }
        return $server;
    }

    public function remove(string $name): bool {
        $this->unregister($name);
        $this->forget($name);
        return $this->servers->remove($name);
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
        foreach ($this->down as $name => [$tries, $next]) {
            if ($next > $now) {
                continue;
            }
            $server = $this->builtinServers[$name] ?? $this->servers->find($name);
            if ($server === null || (!isset($this->builtinServers[$name]) && $server->status !== McpServer::ACTIVE)) {
                unset($this->down[$name]);
                continue;
            }
            try {
                $count = $this->sync($server, false, new NullCancellation());
                unset($this->down[$name]);
                $this->logger->info('MCP server reconnected', ['server' => $name, 'tools' => $count, 'tries' => $tries + 1]);
            } catch (Throwable $e) {
                $this->markDown($name, $tries + 1);
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
            try {
                $this->sync($server, false, new NullCancellation());
            } catch (Throwable $e) {
                $this->logger->warning('MCP server is not reachable', ['server' => $server->name, 'error' => $e::class]);
                $this->markDown($server->name, 0);
            }
        }
    }

    /**
     * Resources of a connected server: uri, name, type, description.
     *
     * @return list<array{uri: string, name: string, description: string, mimeType: string}>
     * @throws DomainException
     */
    public function resources(string $name, Cancellation $cancellation): array {
        $server = $this->connectedServer($name);
        try {
            $list = $this->client->resources($server, $this->token($server), $cancellation);
        } catch (RuntimeException $e) {
            throw new DomainException($e->getMessage());
        }
        return array_map(static fn(array $r): array => [
            'uri' => $r['uri'],
            'name' => self::clean(mb_substr($r['name'], 0, 200)),
            'description' => self::clean(mb_substr($r['description'], 0, self::DESCRIPTION_MAX)),
            'mimeType' => self::clean(mb_substr($r['mimeType'], 0, 100)),
        ], $list);
    }

    /** @throws DomainException */
    public function readResource(string $name, string $uri, Cancellation $cancellation): string {
        $server = $this->connectedServer($name);
        try {
            $text = $this->client->readResource($server, $this->token($server), $uri, $cancellation);
        } catch (RuntimeException $e) {
            throw new DomainException($e->getMessage());
        }
        return mb_substr(self::clean($text), 0, self::RESOURCE_MAX);
    }

    /**
     * The server said its tools changed (a stdio server can, over its open pipe): they are listed again at once, and
     * a changed set turns the server off as at start.
     */
    public function toolsChanged(string $name): void {
        \Amp\async(function () use ($name): void {
            foreach ($this->connected() as $server) {
                if ($server->name !== $name) {
                    continue;
                }
                try {
                    $this->sync($server, false, new NullCancellation());
                } catch (Throwable $e) {
                    $this->logger->warning('MCP tools were not listed again', ['server' => $name, 'error' => $e::class]);
                }
            }
        })->ignore();
    }

    private function forget(string $name): void {
        if ($this->client instanceof McpSessions) {
            $this->client->forget($name);
        }
    }

    /** @return list<McpServer> servers whose tools are in the registry now */
    private function connected(): array {
        $connected = [];
        foreach (array_keys($this->registered) as $name) {
            $server = $this->builtinServers[$name] ?? $this->servers->find($name);
            if ($server !== null && (isset($this->builtinServers[$name]) || $server->status === McpServer::ACTIVE)) {
                $connected[] = $server;
            }
        }
        return $connected;
    }

    /** @throws DomainException */
    private function connectedServer(string $name): McpServer {
        foreach ($this->connected() as $server) {
            if ($server->name === $name) {
                return $server;
            }
        }
        $names = array_map(static fn(McpServer $s): string => $s->name, $this->connected());
        throw new DomainException(sprintf(t('mcp.mcp_manager.not_connected'), $name, $names === [] ? '—' : implode(', ', $names)));
    }

    private function markDown(string $name, int $tries): void {
        $pause = min(self::RETRY_FIRST * (float) (2 ** min($tries, 10)), self::RETRY_MAX);
        $this->down[$name] = [$tries, $this->now() + $pause];
    }

    private function now(): float {
        return $this->clock === null ? microtime(true) : ($this->clock)();
    }

    /** @return list<string> tool names of a server in the registry */
    public function tools(string $name): array {
        return $this->registered[$name] ?? [];
    }

    /** Text without Unicode tags, private-use and other invisible format characters. */
    public static function clean(string $text): string {
        return preg_replace('~[\x{E0000}-\x{E007F}]|[\p{Co}]|(?![\x{200D}])[\p{Cf}]~u', '', $text) ?? '';
    }

    /** @return int how many tools were registered */
    private function sync(McpServer $server, bool $approve, Cancellation $cancellation): int {
        $token = $this->token($server);
        try {
            $tools = $this->client->tools($server, $token, $cancellation);
        } catch (RuntimeException $e) {
            throw new DomainException($e->getMessage());
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
        $hash = hash('sha256', json_encode($cleaned, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        if (!$approve && $server->toolsHash !== null && !hash_equals($server->toolsHash, $hash)) {
            $this->disable($server->name, t('mcp.mcp_manager.nabor_instrumentov_izmenilsya'));
            ($this->tell)(sprintf(t('mcp.mcp_manager.mcp_server_izmenil_instrumenty_imena'), $server->name, $server->name));
            return 0;
        }
        $this->unregister($server->name);
        $names = [];
        foreach ($cleaned as $tool) {
            $name = self::toolName($server->name, $tool['name']);
            if ($this->registry->get($name) !== null || !$server->registers($tool['name'])) {
                continue;
            }
            try {
                $mcpTool = new McpTool($server, $tool['name'], $name, $tool['description'], $tool['schema'], $tool['annotations'], $this->client, fn(): ?string => $this->token($server), $this->breaker);
                if ($this->sandbox !== null && $this->zone !== null) {
                    $mcpTool->useSandbox($this->sandbox, $this->zone);
                }
                $this->registry->register($mcpTool);
            } catch (\InvalidArgumentException $e) {
                // spec: BRIEF.md#решения-по-доработкам-2026-11-18 — a tool with a schema no model can call is left out
                $this->logger->warning('An MCP tool is not registered', ['server' => $server->name, 'tool' => mb_substr($tool['name'], 0, 80), 'problem' => $e->getMessage()]);
                continue;
            }
            $names[] = $name;
        }
        $this->registered[$server->name] = $names;
        if ($approve) {
            $this->servers->update($server->name, McpServer::ACTIVE, $hash, '');
        }
        return count($names);
    }

    private function unregister(string $server): void {
        $this->registry->remove(...($this->registered[$server] ?? []));
        unset($this->registered[$server]);
    }

    private function token(McpServer $server): ?string {
        if (isset($this->builtin[$server->name])) {
            return $this->builtin[$server->name];
        }
        if ($server->secret === null) {
            return null;
        }
        $owner = ($this->owner)();
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
