<?php

declare(strict_types=1);

namespace OpenCCK\Test\Unit\App\Agent;

use Amp\Cancellation;
use Amp\Future;
use Amp\Http\Client\DelegateHttpClient;
use Amp\Http\Client\HttpClient;
use Amp\Http\Client\Request;
use Amp\Http\Client\Response;
use Amp\PHPUnit\AsyncTestCase;
use Mercurius\Messenger\Telegram\TelegramApi;
use Mercurius\Messenger\Telegram\TelegramChannel;
use OpenCCK\App\Agent\ChannelRunObserver;
use OpenCCK\Domain\Agent\Run;
use OpenCCK\Domain\Agent\RunLimits;
use OpenCCK\Domain\Agent\RunStatus;
use OpenCCK\Domain\Agent\StopReason;

use function Amp\async;
use function Amp\delay;

/**
 * The observer over the real Telegram channel: tools that start side by side, then the streamed and final answer.
 */
final class ChannelRunObserverTelegramTest extends AsyncTestCase {
    public function testAnswerAfterToolsThatStartedTogetherReachesTheChat(): void {
        $api = new class implements DelegateHttpClient {
            /** @var list<array{string, string}> method, text */
            public array $calls = [];

            public function request(Request $request, Cancellation $cancellation): Response {
                delay(0.05);
                $method = basename($request->getUri()->getPath());
                $raw = $request->getBody()->getContent()->read() ?? '{}';
                /** @var mixed $decoded */
                $decoded = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
                $text = is_array($decoded) && isset($decoded['text']) && is_string($decoded['text']) ? (string) $decoded['text'] : '';
                $this->calls[] = [$method, $text];
                $result = $method === 'sendMessage' ? '{"message_id":100}' : 'true';
                return new Response('1.1', 200, null, ['content-type' => 'application/json'], '{"ok":true,"result":' . $result . '}', $request);
            }
        };
        $channel = new TelegramChannel(new TelegramApi('1:abc', new HttpClient($api, [])), 0.2);
        $observer = new ChannelRunObserver($channel, '42', '7', 0.1);
        $run = new Run(1, 1, 1, null, 'm', RunStatus::Running, null, new RunLimits(30, 1000, 900, 120), 0, 0, 0, 0);

        $observer->start();
        Future\await([
            async(fn () => $observer->toolStarted($run, 'history_search', '{"query":"кофе"}')),
            async(fn () => $observer->toolStarted($run, 'memory_search', '{"query":"кофе"}')),
        ]);
        foreach (['Нашёл', ': ты просил', ' запомнить'] as $delta) {
            $observer->textDelta($run, $delta);
            delay(0.15);
        }
        $observer->finished($run, "Нашёл: ты просил запомнить\n\n> «кофе»", StopReason::Answered);

        $last = array_slice($api->calls, -1)[0] ?? ['', ''];
        self::assertSame('editMessageText', $last[0], var_export($api->calls, true));
        self::assertStringContainsString('<blockquote>«кофе»</blockquote>', $last[1]);
    }
}
