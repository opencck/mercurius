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
    ) {}

    /** At start: the tools of every active server; a changed one is turned off. */
    public function connectAll(): void {
        foreach ($this->servers->all() as $server) {
            if ($server->status === McpServer::ACTIVE) {
                try {
                    $this->sync($server, false, new NullCancellation());
                } catch (Throwable $e) {
                    $this->logger->warning('MCP server is not reachable', ['server' => $server->name, 'error' => $e::class]);
                }
            }
        }
    }

    /** @throws DomainException */
    public function add(string $name, string $url, ?string $secret): McpServer {
        if (preg_match(McpServer::NAME_PATTERN, $name) !== 1) {
            throw new DomainException('Имя MCP-сервера — строчные латинские буквы, цифры и подчёркивание, до 24 символов.');
        }
        if (preg_match('~^https?://\S+$~', $url) !== 1) {
            throw new DomainException('Адрес MCP-сервера — http(s)://…');
        }
        return $this->servers->add($name, $url, $secret);
    }

    /**
     * The owner's approval: the current tools become the approved set.
     *
     * @return string what was registered
     */
    public function approve(string $name, Cancellation $cancellation): string {
        $server = $this->servers->find($name) ?? throw new DomainException(sprintf('MCP-сервера «%s» нет.', $name));
        $count = $this->sync($server, true, $cancellation);
        return sprintf('MCP-сервер «%s» одобрен: инструментов %d.', $name, $count);
    }

    public function disable(string $name, string $note = 'выключен владельцем'): bool {
        $this->unregister($name);
        return $this->servers->update($name, McpServer::DISABLED, null, $note) !== null;
    }

    public function remove(string $name): bool {
        $this->unregister($name);
        return $this->servers->remove($name);
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
            $this->disable($server->name, 'набор инструментов изменился');
            ($this->tell)(sprintf("MCP-сервер «%s» изменил инструменты (имена, описания или схемы) — я его выключил. Описание инструмента — это указание модели, поэтому смену проверяет владелец: /mcp on %s, чтобы одобрить новый набор.", $server->name, $server->name));
            return 0;
        }
        $this->unregister($server->name);
        $names = [];
        foreach ($cleaned as $tool) {
            $name = self::toolName($server->name, $tool['name']);
            if ($this->registry->get($name) !== null) {
                continue;
            }
            $this->registry->register(new McpTool($server, $tool['name'], $name, $tool['description'], $tool['schema'], $tool['annotations'], $this->client, fn(): ?string => $this->token($server)));
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
        if ($server->secret === null) {
            return null;
        }
        $owner = ($this->owner)();
        $token = $owner === null ? null : $this->vault->reveal($server->secret, $owner);
        return $token === null ? null : trim($token);
    }

    public static function toolName(string $server, string $tool): string {
        $tool = strtolower(preg_replace('~[^A-Za-z0-9_]~', '_', $tool) ?? '');
        return mb_substr(sprintf('mcp_%s_%s', $server, $tool), 0, 64);
    }
}
