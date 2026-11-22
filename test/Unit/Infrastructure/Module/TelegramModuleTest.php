<?php

declare(strict_types=1);

namespace OpenCCK\Test\Unit\Infrastructure\Module;

use Amp\Cancellation;
use Amp\Http\Client\DelegateHttpClient;
use Amp\Http\Client\HttpClient;
use Amp\Http\Client\Request;
use Amp\Http\Client\Response;
use Amp\Http\Client\SocketException;
use Amp\PHPUnit\AsyncTestCase;
use Mercurius\Messenger\IncomingMessage;
use Mercurius\Messenger\MessageHandler;
use Mercurius\Messenger\Telegram\LongPolling;
use Mercurius\Messenger\Telegram\TelegramApi;
use Mercurius\Messenger\Telegram\UpdateParser;
use OpenCCK\Infrastructure\Config\TelegramSettings;
use OpenCCK\Infrastructure\Module\TelegramModule;
use Psr\Log\NullLogger;

use function Amp\delay;

/**
 * spec: brief/channels.md#telegram-прокси-и-локальный-сервер-bot-api
 */
final class TelegramModuleTest extends AsyncTestCase {
    public function testTelegramOutOfReachAtStartIsRetriedAndTheWebhookIsRegisteredOnceItAnswers(): void {
        $http = new class implements DelegateHttpClient {
            public int $failures = 2;
            /** @var list<string> */
            public array $methods = [];

            public function request(Request $request, Cancellation $cancellation): Response {
                $method = basename($request->getUri()->getPath());
                $this->methods[] = $method;
                if ($this->failures > 0) {
                    $this->failures--;
                    throw new SocketException("Connection to 'api.telegram.org:443' failed");
                }
                $result = $method === 'getMe' ? ['id' => 1, 'username' => 'mercurius_bot'] : true;
                return new Response('1.1', 200, null, ['content-type' => 'application/json'], json_encode(['ok' => true, 'result' => $result], JSON_THROW_ON_ERROR), $request);
            }
        };
        $api = new TelegramApi('1:abc', new HttpClient($http, []));
        $settings = TelegramSettings::fromEnv([
            'TELEGRAM_BOT_TOKEN' => '1:' . str_repeat('a', 35),
            'TELEGRAM_ADMIN_ID' => '42',
            'TELEGRAM_WEBHOOK_SECRET' => str_repeat('s', 20),
        ], 'https://example.test');
        $handler = new class implements MessageHandler {
            public function handle(IncomingMessage $message): void {}
        };
        $known = null;
        $module = new TelegramModule($api, $settings, new LongPolling($api, new UpdateParser(), $handler), new NullLogger(), function (array $me) use (&$known): void {
            $known = isset($me['username']) && is_string($me['username']) ? $me['username'] : null;
        }, retryBase: 0.01);

        $module->start(); // does not throw while Telegram is out of reach
        delay(0.2);
        $module->stop();

        self::assertSame(['getMe', 'getMe', 'getMe', 'setWebhook'], $http->methods);
        self::assertSame('mercurius_bot', $known);
    }

    public function testStoppingEndsTheRetries(): void {
        $http = new class implements DelegateHttpClient {
            public int $calls = 0;

            public function request(Request $request, Cancellation $cancellation): Response {
                $this->calls++;
                throw new SocketException('down');
            }
        };
        $api = new TelegramApi('1:abc', new HttpClient($http, []));
        $settings = TelegramSettings::fromEnv(['TELEGRAM_BOT_TOKEN' => '1:' . str_repeat('a', 35), 'TELEGRAM_ADMIN_ID' => '42'], null);
        $handler = new class implements MessageHandler {
            public function handle(IncomingMessage $message): void {}
        };
        $module = new TelegramModule($api, $settings, new LongPolling($api, new UpdateParser(), $handler), new NullLogger(), retryBase: 0.01);

        $module->start();
        delay(0.05);
        $module->stop();
        $calls = $http->calls;
        delay(0.1);

        self::assertGreaterThan(0, $calls);
        self::assertSame($calls, $http->calls, 'no attempt after stop');
    }
}
