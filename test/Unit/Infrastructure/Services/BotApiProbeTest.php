<?php

declare(strict_types=1);

namespace OpenCCK\Test\Unit\Infrastructure\Services;

use Amp\Http\Client\HttpClient;
use Amp\PHPUnit\AsyncTestCase;
use OpenCCK\Infrastructure\Services\BotApiProbe;
use OpenCCK\Test\Support\FakeHttpClient;

/**
 * The local Bot API counts as up only while the bot answers getMe: a hung server still answers its root page.
 */
final class BotApiProbeTest extends AsyncTestCase {
    private const TOKEN = '123456:secret-token-of-the-bot';

    public function testTheBotThatAnswersGetMeIsUp(): void {
        $http = new FakeHttpClient(['http://telegram-api:8081/bot' . self::TOKEN . '/getMe' => [200, [], '{"ok":true,"result":{"id":123456}}']]);

        self::assertTrue((new BotApiProbe(new HttpClient($http, []), 'http://telegram-api:8081/', self::TOKEN))());
        self::assertSame(['GET http://telegram-api:8081/bot' . self::TOKEN . '/getMe'], $http->requested);
    }

    public function testARootPageOrARefusalIsNotUp(): void {
        $root = new FakeHttpClient(['http://telegram-api:8081/' => [404, [], 'not found']]);
        self::assertFalse((new BotApiProbe(new HttpClient($root, []), 'http://telegram-api:8081', self::TOKEN))());

        $refused = new FakeHttpClient(['http://telegram-api:8081/bot' . self::TOKEN . '/getMe' => [200, [], '{"ok":false,"error_code":401}']]);
        self::assertFalse((new BotApiProbe(new HttpClient($refused, []), 'http://telegram-api:8081', self::TOKEN))());
    }
}
