<?php

declare(strict_types=1);

namespace OpenCCK\Domain\Repository;

/**
 * Cookies that servers gave to http_request, kept by scope (a project, else a participant) and host: a session the
 * agent logged into stays for the next requests of that project or participant, and the model never sees it.
 *
 * spec: BRIEF.md#решения-по-доработкам-2026-11-18 — cookies of http_request are kept like browser profiles
 */
interface HttpCookieRepository {
    /** @return array<string, string> name => value of the live cookies of this scope and host */
    public function cookies(string $scope, string $host): array;

    /**
     * Keeps a cookie; an empty value or an expiry in the past removes it.
     *
     * @param int $expires unix time the cookie lives until
     */
    public function keep(string $scope, string $host, string $name, string $value, int $expires): void;
}
