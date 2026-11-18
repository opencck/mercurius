<?php

declare(strict_types=1);

namespace OpenCCK\Test\Integration;

use Amp\PHPUnit\AsyncTestCase;
use OpenCCK\Infrastructure\Repository\PostgresHttpCookieRepository;
use OpenCCK\Test\Support\TestDatabase;

/**
 * @group integration
 */
final class PostgresHttpCookieRepositoryTest extends AsyncTestCase {
    public function testCookiesAreKeptByScopeAndHostAndGoAwayWhenExpired(): void {
        $db = TestDatabase::connect();
        $cookies = new PostgresHttpCookieRepository($db);
        $cookies->keep('project:3', 'panel.example', 'sid', 'one', time() + 3600);
        $cookies->keep('project:3', 'panel.example', 'sid', 'two', time() + 3600);
        $cookies->keep('project:3', 'panel.example', 'lang', 'ru', time() + 3600);
        $cookies->keep('p7', 'panel.example', 'sid', 'mine', time() + 3600);

        self::assertSame(['lang' => 'ru', 'sid' => 'two'], $cookies->cookies('project:3', 'panel.example'), 'a new value replaces the old');
        self::assertSame(['sid' => 'mine'], $cookies->cookies('p7', 'panel.example'));
        self::assertSame([], $cookies->cookies('project:3', 'other.example'));

        $cookies->keep('project:3', 'panel.example', 'sid', 'gone', time() - 1);
        self::assertSame(['lang' => 'ru'], $cookies->cookies('project:3', 'panel.example'), 'an expired cookie removes it');
        $db->close();
    }
}
