<?php

declare(strict_types=1);

namespace OpenCCK\App\Mcp;

use Amp\Cancellation;
use Closure;
use DomainException;
use Mercurius\Llm\ModelCatalog\ModelInfo;
use Mercurius\Mcp\Models\McpModel;
use Mercurius\Mcp\Models\ModelsContract;
use OpenCCK\App\Agent\Model\ProviderRegistry;
use OpenCCK\Domain\Mcp\McpServer;
use OpenCCK\Domain\Repository\ModelCatalogRepository;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

use function Mercurius\I18n\t;

/**
 * An MCP server that offers models becomes a provider of models: `mcp-<entry>`. Only an entry of the shared file of the
 * agent's owners gives one — the lists of models are the agent's, an entry of a member or a project may not put a model
 * into them. The server declares itself by the protocol (ModelsContract), its catalog goes into the catalog of models,
 * and its models are called by its tools. A name already taken keeps the server off; a server that went off leaves its
 * provider known but unavailable.
 *
 * spec: brief/skills-mcp.md#провайдеры-моделей-от-mcp
 */
final class McpModelProviders {
    public const CONTEXT = 'owners';
    public const PREFIX = 'mcp-';

    private ?McpManager $manager = null;

    public function __construct(
        private readonly ProviderRegistry $registry,
        private readonly ModelCatalogRepository $catalog,
        private readonly LoggerInterface $logger = new NullLogger(),
        /** @var ?Closure(McpServer): ?\Mercurius\Llm\LlmClient makes the client of the chat models of a server */
        private readonly ?Closure $chatClient = null,
    ) {}

    public function useManager(McpManager $manager): void {
        $this->manager = $manager;
    }

    public static function providerName(string $entry): string {
        return self::PREFIX . strtolower($entry);
    }

    /** The entry of a provider's owner key: `owners|<entry>` → `<entry>`. */
    public static function entryOf(string $owner): string {
        return str_starts_with($owner, self::CONTEXT . '|') ? substr($owner, strlen(self::CONTEXT) + 1) : $owner;
    }

    /**
     * Whether the cached tools of a connection name the catalog: its process is started to learn its models.
     *
     * @param array<array-key, mixed> $tools
     */
    public static function mayOffer(McpServer $server, array $tools): bool {
        if ($server->context !== self::CONTEXT) {
            return false;
        }
        /** @var mixed $tool */
        foreach ($tools as $tool) {
            if (is_array($tool) && ($tool['name'] ?? null) === ModelsContract::CATALOG) {
                return true;
            }
        }
        return false;
    }

    /**
     * The tools of a connection were just listed from the running server. A server of the owners' file that declares
     * the contract becomes a provider; one that does not is an ordinary server.
     *
     * @param array<array-key, mixed> $capabilities what the server said of itself at the start of its session
     * @param list<string> $tools the names of its tools
     * @param Closure(string, array<string, mixed>, Cancellation): array<array-key, mixed> $call calls a tool of this server
     * @return ?string why the server may not be turned on (the name of its provider is taken); null — it may
     */
    public function connected(McpServer $server, array $capabilities, array $tools, Closure $call, Cancellation $cancellation): ?string {
        if ($server->context !== self::CONTEXT) {
            return null;
        }
        $catalogTool = ModelsContract::catalogTool($capabilities);
        if ($catalogTool === null || !in_array($catalogTool, $tools, true)) {
            // spec: brief/skills-mcp.md#провайдеры-моделей-от-mcp — a server that does not declare the contract is ordinary
            $this->registry->remove($server->key());
            return null;
        }
        $name = self::providerName($server->name);
        $taken = $this->registry->takenBy($name, $server->key());
        if ($taken !== null) {
            $this->registry->remove($server->key());
            return sprintf(t('mcp.providers.taken'), $server->name, $name, $taken);
        }
        try {
            $answer = $call($catalogTool, [], $cancellation);
            $structured = isset($answer[3]) && is_array($answer[3]) ? $answer[3] : null;
            $models = ($answer[1] ?? false) === true ? [] : ModelsContract::models($structured, $tools);
        } catch (Throwable $e) {
            $this->logger->warning('The catalog of an MCP provider was not read', ['server' => $server->key(), 'error' => $e::class]);
            $models = [];
        }
        $chat = array_filter($models, static fn(McpModel $m): bool => $m->kind === 'chat') !== [];
        $client = $this->chatClient === null || !$chat ? null : ($this->chatClient)($server);
        $this->registry->add($name, ProviderRegistry::MCP, $server->key(), sprintf(t('mcp.providers.about'), $server->name), $models, $client);
        if ($models === []) {
            // the server declared the contract but its catalog gave nothing to call: known, with why
            $this->registry->suspend($server->key(), t('mcp.providers.no_models'));
            return null;
        }
        try {
            $this->catalog->replace($name, array_map(self::info(...), $models));
        } catch (Throwable $e) {
            $this->logger->warning('The catalog of an MCP provider was not kept', ['provider' => $name, 'error' => $e::class]);
        }
        $this->logger->info('MCP provider of models', ['provider' => $name, 'models' => count($models)]);
        return null;
    }

    /** The server went off: its provider stays known, unavailable, with why. */
    public function off(string $key, string $reason): void {
        $this->registry->suspend($key, $reason === '' ? t('mcp.providers.off') : $reason);
    }

    /** The entry is gone: so is its provider. */
    public function removed(string $key): void {
        $this->registry->remove($key);
    }

    /**
     * Calls a model of a provider by the tool of its server.
     *
     * @param array<string, mixed> $args
     * @return array{string, bool, array<array-key, mixed>|null, list<array{name: string, type: string, bytes: string}>}
     * @throws DomainException the provider is not available or its server did not answer
     */
    public function call(string $provider, string $tool, array $args, Cancellation $cancellation): array {
        $owner = $this->registry->owner($provider);
        if ($owner === null || !$this->registry->isAvailable($provider) || $this->manager === null) {
            throw new DomainException(sprintf(t('model.catalog.mcp_unavailable'), $provider, $this->registry->reason($provider) ?: '—'));
        }
        return $this->manager->callWithFiles(self::CONTEXT, self::entryOf($owner), $tool, $args, $cancellation);
    }

    public function model(string $provider, string $id, ?string $kind = null): ?McpModel {
        return $this->registry->model($provider, $id, $kind);
    }

    /** A model of the contract as a row of the catalog of models. */
    private static function info(McpModel $model): ModelInfo {
        $output = match ($model->kind) {
            'image' => ['image'],
            'voice', 'music' => ['audio'],
            'film' => ['video'],
            default => ['text'],
        };
        $in = $model->price !== null && $model->price['unit'] === 'mtok_in' ? $model->price['usd'] / 1_000_000.0 : null;
        $out = $model->price !== null && $model->price['unit'] === 'mtok_out' ? $model->price['usd'] / 1_000_000.0 : null;
        return new ModelInfo(
            $model->id,
            $model->title === '' ? $model->id : $model->title,
            $model->context,
            $in ?? ($model->price === null ? null : 0.0),
            $out ?? ($model->price === null ? null : 0.0),
            $model->input,
            $output,
            $model->kind === 'chat' ? ['tools'] : [],
        );
    }
}
