<?php

declare(strict_types=1);

namespace OpenCCK\Infrastructure\Repository;

use OpenCCK\Domain\Repository\HttpCookieRepository;

/** Cookies of http_request in memory: for tests and for a run without a database. */
final class InMemoryHttpCookieRepository implements HttpCookieRepository {
    /** @var array<string, array<string, array{string, int}>> "scope host" => name => [value, expires] */
    private array $jar = [];

    public function cookies(string $scope, string $host): array {
        $now = time();
        $live = [];
        foreach ($this->jar[$scope . ' ' . $host] ?? [] as $name => [$value, $expires]) {
            if ($expires > $now) {
                $live[$name] = $value;
            }
        }
        return $live;
    }

    public function keep(string $scope, string $host, string $name, string $value, int $expires): void {
        $key = $scope . ' ' . $host;
        if ($value === '' || $expires <= time()) {
            unset($this->jar[$key][$name]);
            return;
        }
        $this->jar[$key][$name] = [$value, $expires];
    }
}
