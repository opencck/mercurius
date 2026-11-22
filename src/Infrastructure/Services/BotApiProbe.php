<?php

declare(strict_types=1);

namespace OpenCCK\Infrastructure\Services;

use Amp\Cancellation;
use Amp\CompositeCancellation;
use Amp\Http\Client\HttpClient;
use Amp\Http\Client\Request;
use Amp\TimeoutCancellation;
use SensitiveParameter;
use Throwable;

/**
 * Whether the local Bot API server does its work: the bot answers getMe in time. A hung server still answers its root
 * page at once, while a method of the bot took half a minute — the app then marks the module down, and when it comes back
 * the owner is told. The restart is the watchdog's in the server's container (services/telegram-bot-api/entrypoint.sh).
 *
 * spec: brief/channels.md#здоровье-локального-сервера-bot-api
 */
final class BotApiProbe {
    public function __construct(
        private readonly HttpClient $http,
        private readonly string $baseUrl,
        #[SensitiveParameter] private readonly string $token,
        private readonly float $timeout = 10.0,
    ) {}

    public function __invoke(?Cancellation $cancellation = null): bool {
        $request = new Request(rtrim($this->baseUrl, '/') . '/bot' . $this->token . '/getMe');
        $request->setTransferTimeout($this->timeout);
        $request->setInactivityTimeout($this->timeout);
        $request->setBodySizeLimit(10_000);
        $limit = new TimeoutCancellation($this->timeout);
        try {
            $response = $this->http->request($request, $cancellation === null ? $limit : new CompositeCancellation($cancellation, $limit));
            $body = $response->getBody()->buffer();
            /** @var mixed $answer an answer of the server, checked below */
            $answer = json_decode($body, true);
            return $response->getStatus() === 200 && is_array($answer) && ($answer['ok'] ?? false) === true;
        } catch (Throwable) {
            return false;
        }
    }
}
