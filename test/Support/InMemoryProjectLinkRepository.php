<?php

declare(strict_types=1);

namespace OpenCCK\Test\Support;

use OpenCCK\Domain\Repository\ProjectLinkRepository;

final class InMemoryProjectLinkRepository implements ProjectLinkRepository {
    /** @var array<string, true> "project:principal" */
    public array $subscriptions = [];
    /** @var array<int, array{projectId: int, host: string}> by secret */
    public array $secrets = [];

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

    public function bindSecret(int $secretId, int $projectId, string $host): void {
        $this->secrets[$secretId] = ['projectId' => $projectId, 'host' => $host];
    }

    public function unbindSecret(int $secretId): bool {
        $had = isset($this->secrets[$secretId]);
        unset($this->secrets[$secretId]);
        return $had;
    }

    public function secretBinding(int $secretId): ?array {
        return $this->secrets[$secretId] ?? null;
    }

    public function secretsOf(int $projectId): array {
        $found = [];
        foreach ($this->secrets as $secret => $binding) {
            if ($binding['projectId'] === $projectId) {
                $found[] = ['secretId' => $secret, 'host' => $binding['host']];
            }
        }
        return $found;
    }
}
