<?php

declare(strict_types=1);

namespace OpenCCK\Infrastructure\Module;

use Amp\CancelledException;
use Amp\DeferredCancellation;
use Amp\Future;
use Closure;
use Mercurius\Messenger\Telegram\LongPolling;
use Mercurius\Messenger\Telegram\TelegramApi;
use OpenCCK\Infrastructure\API\AppModuleInterface;
use OpenCCK\Infrastructure\Config\TelegramSettings;
use Psr\Log\LoggerInterface;
use Throwable;

use function Amp\async;
use function Amp\delay;

/**
 * Connects the bot to Telegram: registers the webhook when the service has a public address,
 * otherwise runs long polling (local development).
 *
 * Telegram out of reach at start (a proxy not up yet, a network blip) does not stop the process: the connection is
 * tried again in the background, 5 s doubling up to a minute, until it answers or the module stops. The rest of the
 * agent (WebSocket API, jobs, the agent network) works meanwhile.
 *
 * ref: references/hermes-agent/plugins/platforms/telegram/adapter.py@e96221fa:L1713-L1738 (_schedule_polling_recovery),
 * L1910-L1926 — a failed connect degrades only the adapter and is retried in the background, 5 s doubling to 60 s;
 * unlike hermes there is no limit of attempts: no supervisor here would rebuild the adapter.
 *
 * spec: brief/channels.md#telegram-прокси-и-локальный-сервер-bot-api
 */
final class TelegramModule implements AppModuleInterface {
    private const MAX_DELAY = 60.0;

    private ?DeferredCancellation $running = null;
    /** @var Future<void>|null */
    private ?Future $connecting = null;
    /** @var Future<void>|null */
    private ?Future $pollingFuture = null;

    /**
     * @param (Closure(array<array-key, mixed>): void)|null $onConnected told what getMe answered, once connected
     */
    public function __construct(
        private readonly TelegramApi $api,
        private readonly TelegramSettings $settings,
        private readonly LongPolling $longPolling,
        private readonly LoggerInterface $logger,
        private readonly ?Closure $onConnected = null,
        private readonly float $retryBase = 5.0,
    ) {}

    public function start(): void {
        $this->running = new DeferredCancellation();
        $cancellation = $this->running->getCancellation();
        $this->connecting = async(function () use ($cancellation): void {
            for ($attempt = 1; !$cancellation->isRequested(); $attempt++) {
                try {
                    $this->connect();
                    return;
                } catch (CancelledException) {
                    return;
                } catch (Throwable $e) {
                    $wait = min($this->retryBase * (float) (2 ** min($attempt - 1, 16)), self::MAX_DELAY);
                    $this->logger->warning('Telegram is out of reach; trying again', ['attempt' => $attempt, 'in' => $wait, 'error' => $e::class]);
                    try {
                        delay($wait, cancellation: $cancellation);
                    } catch (CancelledException) {
                        return;
                    }
                }
            }
        });
    }

    private function connect(): void {
        $me = $this->api->getMe();
        $this->logger->info('Telegram bot connected', ['username' => $me['username'] ?? null]);
        if ($this->onConnected !== null) {
            ($this->onConnected)($me);
        }

        $url = $this->settings->webhookUrl;
        $secret = $this->settings->webhookSecret();
        if ($url !== null && $secret !== null) {
            // Presses on buttons (approvals, visitors, grants) come as callback queries.
            // spec: brief/channels.md#группа — the owner's card comes as soon as the bot is added (my_chat_member);
            // spec: brief/channels.md#канал-паблик — the bot's rights in a channel and its posts
            $this->api->setWebhook($url, $secret, ['message', 'callback_query', 'my_chat_member', 'channel_post', 'edited_channel_post']);
            $this->logger->info('Telegram webhook registered', ['url' => $url]);
            return;
        }

        $cancellation = $this->running?->getCancellation();
        $this->pollingFuture = async(function () use ($cancellation): void {
            try {
                if ($cancellation !== null) {
                    $this->longPolling->run($cancellation);
                }
            } catch (Throwable $e) {
                $this->logger->error('Telegram long polling stopped', ['error' => $e::class]);
            }
        });
        $this->logger->info('Telegram long polling started');
    }

    public function stop(): void {
        $this->running?->cancel();
        $this->connecting?->ignore();
        $this->longPolling->stop();
        $this->pollingFuture?->ignore();
        $this->longPolling->dispatcher()->awaitIdle();
    }
}
