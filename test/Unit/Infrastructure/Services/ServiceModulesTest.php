<?php

declare(strict_types=1);

namespace OpenCCK\Test\Unit\Infrastructure\Services;

use Amp\Http\Client\HttpClient;
use Amp\PHPUnit\AsyncTestCase;
use OpenCCK\Infrastructure\Services\ServiceModules;
use OpenCCK\Test\Support\FakeHttpClient;

/**
 * A module service counts only while it answers /health; one that is not set up is never up.
 */
final class ServiceModulesTest extends AsyncTestCase {
    public function testUpOnlyAfterHealthAnswers(): void {
        $http = new FakeHttpClient(['http://media-video:8091/health' => [200, [], '{"status":"ok"}']]);
        $modules = new ServiceModules(new HttpClient($http, []));
        $modules->add('media-video', 'http://media-video:8091/', 'token-0123456789abcdef');
        $modules->add('media-docs', 'http://media-docs:8092', 'token-0123456789abcdef');

        self::assertFalse($modules->up('media-video'), 'not checked yet');
        $modules->check();

        self::assertTrue($modules->up('media-video'));
        self::assertFalse($modules->up('media-docs'), 'a 404 is not healthy');
        self::assertFalse($modules->up('browser'), 'not set up');
        self::assertSame(['media-video' => true, 'media-docs' => false], $modules->states());
        self::assertSame('http://media-video:8091', $modules->url('media-video'));
        self::assertSame('token-0123456789abcdef', $modules->token('media-video'));
        self::assertSame(['GET http://media-video:8091/health', 'GET http://media-docs:8092/health'], $http->requested);
    }

    public function testGoesDownWhenHealthStops(): void {
        $healthy = new FakeHttpClient(['http://m:1/health' => [200, [], 'ok']]);
        $modules = new ServiceModules(new HttpClient($healthy, []));
        $modules->add('m', 'http://m:1', 'token-0123456789abcdef');
        $modules->check();
        self::assertTrue($modules->up('m'));

        $gone = new ServiceModules(new HttpClient(new FakeHttpClient(), []));
        $gone->add('m', 'http://m:1', 'token-0123456789abcdef');
        $gone->check();
        self::assertFalse($gone->up('m'));
    }

    public function testAModuleWithoutATokenIsNotAdded(): void {
        $modules = new ServiceModules(new HttpClient(new FakeHttpClient(), []));
        self::assertFalse($modules->add('m', 'http://m:1', null));
        self::assertFalse($modules->add('m', 'http://m:1', ''));
        self::assertSame([], $modules->states());
    }
}
