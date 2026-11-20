<?php

declare(strict_types=1);

namespace OpenCCK\Domain\Repository;

/**
 * What hangs on a project besides its members: who listens to it and which secrets were given to it.
 *
 * spec: brief/network.md#проект-как-канал-для-агентов
 * spec: brief/participants.md#ресурсы-проекта
 */
interface ProjectLinkRepository {
    public function subscribe(int $projectId, int $principalId): void;

    public function unsubscribe(int $projectId, int $principalId): bool;

    public function subscribed(int $projectId, int $principalId): bool;

    /** @return list<int> the projects the participant listens to */
    public function subscriptionsOf(int $principalId): array;

    /** Gives a secret to a project for requests to one host; a secret belongs to one project at a time. */
    public function bindSecret(int $secretId, int $projectId, string $host): void;

    public function unbindSecret(int $secretId): bool;

    /** @return array{projectId: int, host: string}|null */
    public function secretBinding(int $secretId): ?array;

    /** @return list<array{secretId: int, host: string}> */
    public function secretsOf(int $projectId): array;
}
