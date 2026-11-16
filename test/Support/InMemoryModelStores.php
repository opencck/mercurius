<?php

declare(strict_types=1);

namespace OpenCCK\Test\Support;

use DateTimeImmutable;
use Mercurius\Llm\ModelCatalog\ModelInfo;
use OpenCCK\Domain\Agent\ModelRef;
use OpenCCK\Domain\Agent\ModelRole;
use OpenCCK\Domain\Agent\RoutingDecision;
use OpenCCK\Domain\Repository\ModelCatalogRepository;
use OpenCCK\Domain\Repository\ModelRoleRepository;
use OpenCCK\Domain\Repository\RoutingDecisionRepository;

/** Catalog, roles and routing log in memory. */
final class InMemoryModelStores implements ModelCatalogRepository, ModelRoleRepository, RoutingDecisionRepository {
    /** @var array<string, array<string, ModelInfo>> provider => id => model */
    public array $models = [];
    /** @var array<string, DateTimeImmutable> */
    public array $fetched = [];
    /** @var array<string, ModelRole> */
    public array $roles = [];
    /** @var list<RoutingDecision> */
    public array $decisions = [];

    public function replace(string $provider, array $models): void {
        $this->models[$provider] = [];
        foreach ($models as $model) {
            $this->models[$provider][$model->id] = $model;
        }
        $this->fetched[$provider] = new DateTimeImmutable();
    }

    public function find(ModelRef $model): ?ModelInfo {
        return $this->models[$model->provider][$model->model] ?? null;
    }

    public function models(string $provider): array {
        $models = $this->models[$provider] ?? [];
        ksort($models);
        return array_values($models);
    }

    public function fetchedAt(string $provider): ?DateTimeImmutable {
        return ($this->models[$provider] ?? []) === [] ? null : ($this->fetched[$provider] ?? null);
    }

    public function all(): array {
        return $this->roles;
    }

    public function set(string $role, ModelRef $model, string $reason, string $setBy, ?int $principalId = null, bool $byOwner = false): bool {
        if (($this->roles[$role] ?? null)?->setBy === ModelRole::SET_BY_OWNER && $setBy !== ModelRole::SET_BY_OWNER && !$byOwner) {
            return false;
        }
        $this->roles[$role] = new ModelRole($role, $model, $reason, $setBy, $principalId);
        return true;
    }

    public function seed(array $models, string $reason): void {
        foreach ($models as $role => $model) {
            $this->roles[$role] ??= new ModelRole($role, $model, $reason, ModelRole::SET_BY_SEED);
        }
    }

    public function record(RoutingDecision $decision): void {
        $this->decisions[] = $decision;
    }
}
