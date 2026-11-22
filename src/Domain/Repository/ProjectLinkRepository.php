<?php

declare(strict_types=1);

namespace OpenCCK\Domain\Repository;

/**
 * What hangs on a project besides its members: who listens to it.
 *
 * spec: brief/network.md#проект-как-канал-для-агентов
 */
interface ProjectLinkRepository {
    public function subscribe(int $projectId, int $principalId): void;

    public function unsubscribe(int $projectId, int $principalId): bool;

    public function subscribed(int $projectId, int $principalId): bool;

    /** @return list<int> the projects the participant listens to */
    public function subscriptionsOf(int $principalId): array;

    /** @return list<int> the participants that listen to the project */
    public function subscribers(int $projectId): array;
}
