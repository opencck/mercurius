<?php

declare(strict_types=1);

namespace OpenCCK\App\Agent;

use Mercurius\Messenger\Channel;
use Mercurius\Messenger\OutgoingMessage;
use Mercurius\Messenger\ParseMode;
use Mercurius\Messenger\TextSplitter;
use OpenCCK\Domain\Agent\Delivery;
use OpenCCK\Domain\Repository\DeliveryRepository;
use Psr\Log\LoggerInterface;
use Throwable;

use function Mercurius\I18n\t;

/**
 * At start: final answers that did not reach their chat are sent again, from the first part that was not delivered.
 * One that was never sent goes as it is; one whose send was under way or refused may already be there, so it says it
 * may be a duplicate. After three refused attempts or a day an answer is given up.
 *
 * spec: brief/agent-loop.md#подходы
 * ref: references/hermes-agent/gateway/delivery_ledger.py@e96221fa:L1-L11, L33-L40 — pending, attempting, delivered,
 *      failed, abandoned; a redelivery after a crash mid-send carries a visible "may be a duplicate" marker; three
 *      attempts, a day of life; here the parts already delivered are not sent again
 */
final class DeliveryRecovery {
    public const MAX_ATTEMPTS = 3;
    public const MAX_AGE = 86400;

    public function __construct(
        private readonly DeliveryRepository $deliveries,
        private readonly Channel $channel,
        private readonly LoggerInterface $logger,
    ) {}

    public function recover(): void {
        try {
            $open = $this->deliveries->undelivered();
        } catch (Throwable $e) {
            $this->logger->warning('Deliveries were not read', ['error' => $e::class]);
            return;
        }
        foreach ($open as $delivery) {
            if ($delivery->attempts >= self::MAX_ATTEMPTS || time() - $delivery->createdAt > self::MAX_AGE) {
                $this->deliveries->abandon($delivery->id);
                $this->logger->warning('Answer given up', ['delivery' => $delivery->id, 'run' => $delivery->runId]);
                continue;
            }
            $this->redeliver($delivery);
        }
    }

    private function redeliver(Delivery $delivery): void {
        $mode = ParseMode::tryFrom($delivery->parseMode) ?? ParseMode::Plain;
        $parts = (new TextSplitter($this->channel->capabilities()->maxMessageLength))->split($delivery->text);
        $left = array_slice($parts, $delivery->sent);
        if ($left === []) {
            $this->deliveries->delivered($delivery->id);
            return;
        }
        if ($delivery->status !== Delivery::PENDING) {
            $left[0] = t('agent.delivery.recovered') . $left[0];
        }
        $this->deliveries->attempting($delivery->id);
        $sent = $delivery->sent;
        foreach ($left as $i => $part) {
            $result = $this->channel->send(new OutgoingMessage($delivery->chatId, $part, $i === 0 && $sent === 0 ? $delivery->replyTo : null, $mode, threadId: $delivery->threadId));
            if (!$result->success) {
                $this->deliveries->failed($delivery->id, $result->error ?? '');
                $this->logger->warning('Answer was not redelivered', ['delivery' => $delivery->id, 'kind' => $result->errorKind?->value]);
                return;
            }
            $this->deliveries->progress($delivery->id, ++$sent);
        }
        $this->deliveries->delivered($delivery->id);
        $this->logger->notice('Answer redelivered', ['delivery' => $delivery->id, 'run' => $delivery->runId]);
    }
}
