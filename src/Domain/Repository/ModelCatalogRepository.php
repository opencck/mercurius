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

    /** When the provider's catalog was last loaded; null when it is empty. */
    public function fetchedAt(string $provider): ?DateTimeImmutable;
}
