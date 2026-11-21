<?php

declare(strict_types=1);

namespace OpenCCK\Domain\Repository;

/**
 * The rights a project's owner set for it, in the form of a participant's policy overrides.
 *
 * spec: brief/participants.md#права-проекта
 */
interface ProjectPolicyRepository {
    /** @return array<string, mixed>|null the project's rights, or null when it works with its owner's */
    public function find(int $projectId): ?array;

    /** @param array<string, mixed> $policy */
    public function save(int $projectId, array $policy): void;

    /** @return list<int> the projects that have rights of their own */
    public function projects(): array;
}
