<?php

declare(strict_types=1);

namespace OpenCCK\Test\Support;

use OpenCCK\Domain\Repository\ProjectLinkRepository;

final class InMemoryProjectLinkRepository implements ProjectLinkRepository {
    /** @var array<string, true> "project:principal" */
    public array $subscriptions = [];

    public function subscribe(int $projectId, int $principalId): void {
        $this->subscriptions[$projectId . ':' . $principalId] = true;
    }

    public function subscribers(int $projectId): array {
        $ids = [];
        foreach (array_keys($this->subscriptions) as $key) {
            [$project, $principal] = array_map('intval', explode(':', $key));
            if ($project === $projectId) {
                $ids[] = $principal;
            }
        }
        sort($ids);
        return $ids;
    }

    public function unsubscribe(int $projectId, int $principalId): bool {
        $had = isset($this->subscriptions[$projectId . ':' . $principalId]);
        unset($this->subscriptions[$projectId . ':' . $principalId]);
        return $had;
    }

    public function subscribed(int $projectId, int $principalId): bool {
        return isset($this->subscriptions[$projectId . ':' . $principalId]);
    }

    public function subscriptionsOf(int $principalId): array {
        $found = [];
        foreach (array_keys($this->subscriptions) as $key) {
            [$project, $principal] = array_map('intval', explode(':', $key));
            if ($principal === $principalId) {
                $found[] = $project;
            }
        }
        sort($found);
        return $found;
    }
}
