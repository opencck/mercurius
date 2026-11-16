<?php

declare(strict_types=1);

namespace OpenCCK\Infrastructure\Module;

use Amp\DeferredCancellation;
use Amp\Future;
use Mercurius\Messenger\Telegram\LongPolling;
use Mercurius\Messenger\Telegram\TelegramApi;
use OpenCCK\Infrastructure\API\AppModuleInterface;
use OpenCCK\Infrastructure\Config\TelegramSettings;
use Psr\Log\LoggerInterface;
use Throwable;

use function Amp\async;

/**
 * Connects the bot to Telegram: registers the webhook when the service has a public address,
 * otherwise runs long polling (local development).
 */
final class TelegramModule implements AppModuleInterface {
    private ?DeferredCancellation $polling = null;
    /** @var Future<void>|null */
    private ?Future $pollingFuture = null;

    public function __construct(
        private readonly TelegramApi $api,
        private readonly TelegramSettings $settings,
        private readonly LongPolling $longPolling,
        private readonly LoggerInterface $logger,
    ) {}

    public function start(): void {
        $me = $this->api->getMe();
        $this->logger->info('Telegram bot connected', ['username' => $me['username'] ?? null]);

        $url = $this->settings->webhookUrl;
        $secret = $this->settings->webhookSecret();
        if ($url !== null && $secret !== null) {
            $this->api->setWebhook($url, $secret, ['message']);
            $this->logger->info('Telegram webhook registered', ['url' => $url]);
            return;
        }

        $this->polling = new DeferredCancellation();
        $cancellation = $this->polling->getCancellation();
        $this->pollingFuture = async(function () use ($cancellation): void {
            try {
                $this->longPolling->run($cancellation);
            } catch (Throwable $e) {
                $this->logger->error('Telegram long polling stopped', ['error' => $e::class]);
            }
        });
        $this->logger->info('Telegram long polling started');
    }

    public function stop(): void {
        $this->polling?->cancel();
        $this->longPolling->stop();
        $this->pollingFuture?->ignore();
        $this->longPolling->dispatcher()->awaitIdle();
    }
}
