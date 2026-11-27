<?php

declare(strict_types=1);

namespace OpenCCK\App\Agent\Model;

use DomainException;
use Mercurius\Llm\LlmClient;
use Mercurius\Mcp\Models\McpModel;

use function Mercurius\I18n\t;

/**
 * The providers of models that come and go while the application runs — now the ones MCP servers offer, later the ones
 * of neighbour agents. The providers built into the installation (by a key, a subscription, a module) stay where they
 * are made at start; their names are reserved here, so nothing that comes later takes one.
 *
 * A provider is available, or known but unavailable with a reason (its MCP is off, did not start, waits for approval).
 * The models of an unavailable provider are not called; a category that holds one goes on with its next model.
 *
 * spec: brief/models.md#реестр-провайдеров
 */
final class ProviderRegistry {
    public const MCP = 'mcp';

    /** @var array<string, array{source: string, owner: string, about: string, available: bool, reason: string, client: ?LlmClient, models: list<McpModel>}> */
    private array $providers = [];

    /** @param list<string> $reserved the names of the providers the installation knows, whether or not they have a key */
    public function __construct(private readonly array $reserved = []) {}

    /**
     * Who holds a name now; null — it is free for this owner.
     */
    public function takenBy(string $name, string $owner): ?string {
        if (in_array($name, $this->reserved, true)) {
            return t('model.registry.builtin');
        }
        $held = $this->providers[$name] ?? null;
        return $held === null || $held['owner'] === $owner ? null : $held['owner'];
    }

    /**
     * Puts a provider in, available; its owner may put it in again with new models.
     *
     * @param list<McpModel> $models
     * @throws DomainException the name is taken
     */
    public function add(string $name, string $source, string $owner, string $about, array $models, ?LlmClient $client = null): void {
        $by = $this->takenBy($name, $owner);
        if ($by !== null) {
            throw new DomainException(sprintf(t('model.registry.taken'), $name, $by));
        }
        $this->providers[$name] = ['source' => $source, 'owner' => $owner, 'about' => $about, 'available' => true, 'reason' => '', 'client' => $client, 'models' => $models];
    }

    /** The provider stays known but is not called; the reason is told in the list of providers. */
    public function suspend(string $owner, string $reason): void {
        foreach ($this->providers as $name => $provider) {
            if ($provider['owner'] === $owner) {
                $this->providers[$name] = ['available' => false, 'reason' => $reason] + $provider;
            }
        }
    }

    /** The provider is gone altogether. */
    public function remove(string $owner): void {
        foreach ($this->providers as $name => $provider) {
            if ($provider['owner'] === $owner) {
                unset($this->providers[$name]);
            }
        }
    }

    /** @return list<string> the providers that can be called now */
    public function available(): array {
        return array_keys(array_filter($this->providers, static fn(array $p): bool => $p['available']));
    }

    /** @return array<string, array{source: string, owner: string, about: string, available: bool, reason: string, client: ?LlmClient, models: list<McpModel>}> */
    public function all(): array {
        return $this->providers;
    }

    public function client(string $name): ?LlmClient {
        $provider = $this->providers[$name] ?? null;
        return $provider !== null && $provider['available'] ? $provider['client'] : null;
    }

    /** The model of a provider, of a kind when one is named; null — not a model of a provider here. */
    public function model(string $provider, string $id, ?string $kind = null): ?McpModel {
        foreach ($this->providers[$provider]['models'] ?? [] as $model) {
            if ($model->id === $id && ($kind === null || $model->kind === $kind)) {
                return $model;
            }
        }
        return null;
    }

    public function owner(string $provider): ?string {
        return $this->providers[$provider]['owner'] ?? null;
    }

    public function isAvailable(string $provider): bool {
        return ($this->providers[$provider]['available'] ?? false) === true;
    }

    public function reason(string $provider): string {
        return $this->providers[$provider]['reason'] ?? '';
    }
}
