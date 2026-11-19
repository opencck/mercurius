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

    /** @var array<string, bool> ref => calls tools well */
    public array $toolReliable = [];

    public function markToolReliable(string $provider, array $ids): void {
        foreach (array_keys($this->models[$provider] ?? []) as $id) {
            $this->toolReliable[$provider . ':' . $id] = in_array($id, $ids, true);
        }
    }

    public function toolUnreliable(): array {
        return array_keys(array_filter($this->toolReliable, static fn(bool $good): bool => !$good));
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
        array_unshift($this->roleHistory, ['id' => count($this->roleHistory) + 1, 'role' => $role, 'model' => $model, 'reason' => $reason, 'setBy' => $setBy, 'by' => $principalId, 'at' => time()]);
        return true;
    }

    /** @var list<array{id: int, role: string, model: ModelRef, reason: string, setBy: string, by: ?int, at: int}> */
    public array $roleHistory = [];

    public function history(int $limit): array {
        return array_slice($this->roleHistory, 0, $limit);
    }

    public function seed(array $models, string $reason): void {
        foreach ($models as $role => $model) {
            $this->roles[$role] ??= new ModelRole($role, $model, $reason, ModelRole::SET_BY_SEED);
        }
    }

    public function openingRoles(array $conversationIds): array {
        $roles = [];
        foreach ($this->decisions as $d) {
            if ($d->conversationId !== null && in_array($d->conversationId, $conversationIds, true)
                && (in_array($d->class, ['short', 'normal', 'complex'], true) || $d->method === 'role_change')) {
                $roles[$d->conversationId] = $d->role;
            }
        }
        return $roles;
    }

    public function record(RoutingDecision $decision): void {
        $this->decisions[] = $decision;
    }
}
