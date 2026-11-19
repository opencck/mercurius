<?php

declare(strict_types=1);

namespace OpenCCK\Domain\Repository;

use DateTimeImmutable;
use Mercurius\Llm\ModelCatalog\ModelInfo;
use OpenCCK\Domain\Agent\ModelRef;

interface ModelCatalogRepository {
    /**
     * Replaces the catalog of one provider in one transaction; other providers are kept.
     *
     * @param list<ModelInfo> $models
     */
    public function replace(string $provider, array $models): void;

    public function find(ModelRef $model): ?ModelInfo;

    /** @return list<ModelInfo> every model of the provider, by id */
    public function models(string $provider): array;

    /** When the provider's catalog was last loaded; null when it is empty. */
    public function fetchedAt(string $provider): ?DateTimeImmutable;

    /**
     * The models of the provider whose tool calls pass their schemas often enough by the catalog; the others of the
     * provider are marked as calling tools badly. Until marked, nothing is known.
     *
     * @param list<string> $ids
     */
    public function markToolReliable(string $provider, array $ids): void;

    /** @return list<string> refs ("provider:id") of the models known to call tools badly */
    public function toolUnreliable(): array;
}
